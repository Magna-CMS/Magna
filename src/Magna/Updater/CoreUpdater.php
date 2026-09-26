<?php

declare(strict_types=1);

namespace Magna\Updater;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\ComposerRunner;
use Magna\Plugins\PluginCompatibilityCheck;
use Magna\Support\StaleClassMap;
use Magna\Updater\Engine\EngineContext;
use Magna\Updater\Engine\EngineLoader;
use Magna\Updater\Engine\PathGuard;
use Magna\Updater\Engine\StagedSwap;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\Preflight\UpdatePreflight;
use Magna\Updater\Run\RunRollback;
use Magna\Updater\Run\RunState;
use Magna\Updater\Run\UpdateJournal;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * Applies a published core release. Modeled on Magna\Marketplace\PluginInstaller
 * — same lock-and-poll shape, same fail-with-message pattern, progress written
 * to cache for the UI.
 *
 * Three tiers, decided from the archive itself (ReleasePlanner):
 *
 *  - A release that carries a manifest AND an engine this updater can run
 *    is HANDED OFF: the release's own engine stages and switches the paths
 *    it names (two renames per path, the previous content kept beside it),
 *    then this process steps back. Migrations, caches and the all-clear run
 *    under the NEW code (Run\UpdateFinalizer), reached by whichever process
 *    gets there first. This is what lets a release deliver something it
 *    introduced: the installed updater no longer decides what an update
 *    does, only what it may.
 *  - A release with a manifest but no runnable engine is applied here, by
 *    the manifest's path list, the way every update used to be.
 *  - A release with no manifest (every archive up to 1.4.4) is applied here
 *    by CoreOwnedPaths.
 *
 * Only the engine tier touches vendor/, and only as the release's manifest
 * asks and the site allows — see Engine\EngineContext. The in-place tiers
 * never do, beyond the SDK: a customer's vendor/ may hold packages a release
 * knows nothing about.
 *
 * Out of scope: a generic, cross-driver DB dump/restore. Rollback restores
 * files, never the database; the site owner's own DB backup is the recovery
 * path if a migration fails partway.
 */
class CoreUpdater
{
    private const LOCK_KEY = 'magna.updater.apply.lock';

    /**
     * The one vendor path core owns outright.
     *
     * Safe to overlay where the rest of vendor/ is not: it holds no customer
     * packages, no state and no configuration — only the interfaces and value
     * objects plugins are compiled against.
     */
    public const SDK_PATH = 'vendor/magna-cms/plugin-sdk';

    /**
     * Where a hub build stages the SDK's own source for its path repository.
     *
     * Composer installs that repository with `symlink: false`, so vendor/ holds
     * a copy rather than a link — leaving this behind means Composer rebuilds
     * the old SDK into vendor/ on the next install. Kept in step with
     * `release_bundle_relative_path()` in bin/build-release.php, which is what
     * decides this location when a hub archive is staged.
     */
    public const SDK_SOURCE_PATH = 'bundled/magna-cms/plugin-sdk';

    /** Version being applied, so every progress line can name it ("v1.3.4 — Downloading release…"). */
    private ?string $targetVersion = null;

    /**
     * The paths a one-click update overlays when the archive carries no
     * manifest, and the list a manifest-carrying release is compared against.
     * Public so the installer's requirements screen and CoreWritability can
     * report on the same list, instead of keeping a second copy that drifts.
     *
     * @return list<string>
     */
    public static function coreOwnedPaths(): array
    {
        return CoreOwnedPaths::legacy();
    }

    public function __construct(
        private readonly Filesystem $files,
        private readonly ReleaseArchive $archive,
        private readonly ReleasePlanner $planner,
        private readonly EngineLoader $engines,
        private readonly PathGuard $guard,
        private readonly CoreSnapshot $snapshot,
        private readonly InPlaceOverlay $overlay,
        private readonly UpdatePaths $paths,
        private readonly UpdatePreflight $preflight,
        private readonly UpdateHousekeeping $housekeeping,
        private readonly UpdateRunLog $runLog,
        private readonly InstalledFootprint $footprint,
        private readonly MaintenanceWindow $maintenance,
        private readonly RuntimeRefresh $refresh,
        private readonly RunRollback $rollback,
        private readonly PluginCompatibilityCheck $compatibility,
        private readonly ComposerRunner $composer,
    ) {}

    /**
     * @param  string|null  $maintenanceSecret  the key `down --secret` takes; the admin who started the run holds the matching bypass cookie (CoreUpdateStarter)
     */
    public function apply(
        string $targetVersion,
        string $zipUrl,
        ?string $expectedSha256,
        bool $force = false,
        ?string $checksumSignature = null,
        ?string $maintenanceSecret = null,
    ): CoreUpdateState {
        $this->targetVersion = $targetVersion;
        $this->runLog->start($targetVersion);
        $this->setProgress(CoreUpdateState::Running, 'Starting…', 2);

        $refusal = $this->refusal($targetVersion, $expectedSha256, $checksumSignature, UpdateMode::Update, requireSignature: true);
        if ($refusal !== null) {
            return $this->fail($refusal);
        }

        return $this->execute(
            UpdateMode::Update,
            $targetVersion,
            fn (): array => [$this->archive->download($zipUrl), true],
            (string) $expectedSha256,
            $force,
            $maintenanceSecret,
        );
    }

    /**
     * Re-apply the release this install already runs, from the archive
     * Update Manager publishes for it — verified and signed exactly as an
     * update is. For a site an older updater brought here: the code arrived
     * and nothing recorded whether everything else did.
     */
    public function repairFromHub(string $zipUrl, ?string $expectedSha256, ?string $checksumSignature = null, ?string $maintenanceSecret = null): CoreUpdateState
    {
        $target = MagnaServiceProvider::VERSION;
        $this->targetVersion = $target;
        $this->runLog->start($target);
        $this->setProgress(CoreUpdateState::Running, 'Starting repair…', 2);

        $refusal = $this->refusal($target, $expectedSha256, $checksumSignature, UpdateMode::Repair, requireSignature: true);
        if ($refusal !== null) {
            return $this->fail($refusal);
        }

        return $this->execute(
            UpdateMode::Repair,
            $target,
            fn (): array => [$this->archive->download($zipUrl), true],
            (string) $expectedSha256,
            false,
            $maintenanceSecret,
        );
    }

    /**
     * The same repair from an archive already on this server. The operator
     * supplies the checksum from the published sidecar — that, not a
     * signature, is the trust here; the archive is theirs and left in place.
     */
    public function repair(string $archivePath, string $expectedSha256, ?string $maintenanceSecret = null): CoreUpdateState
    {
        $target = MagnaServiceProvider::VERSION;
        $this->targetVersion = $target;
        $this->runLog->start($target);
        $this->setProgress(CoreUpdateState::Running, 'Starting repair…', 2);

        if (! is_file($archivePath)) {
            return $this->fail("No archive at {$archivePath}.");
        }

        $refusal = $this->refusal($target, $expectedSha256, null, UpdateMode::Repair, requireSignature: false);
        if ($refusal !== null) {
            return $this->fail($refusal);
        }

        return $this->execute(
            UpdateMode::Repair,
            $target,
            static fn (): array => [$archivePath, false],
            $expectedSha256,
            false,
            $maintenanceSecret,
        );
    }

    /**
     * Current apply progress, read the same way Marketplace\PluginInstaller::progress() is.
     *
     * @return array{state: string|null, message: string, percent: int, version: string|null, log: list<array{message: string, percent: int}>, waiting_seconds: int}
     */
    public static function progress(): array
    {
        return CoreUpdateProgress::read();
    }

    /** @see CoreUpdateProgress::markQueued() */
    public static function markQueued(PendingCoreUpdate $pending): void
    {
        CoreUpdateProgress::markQueued($pending);
    }

    /**
     * Enabled plugins whose manifest compat range does not satisfy the target
     * core version. Public so the admin UI can pre-flight this before dispatching
     * the update job and offer the admin a choice; apply() re-checks it itself
     * regardless, since it cannot trust the caller ran this first.
     *
     * @return list<IncompatiblePlugin>
     */
    public function checkCompatibility(string $targetVersion): array
    {
        return $this->compatibility->incompatibleWithCore($targetVersion);
    }

    /**
     * Why nothing may start, or null. Fail-closed on the checksum: this
     * replaces the code that runs on every request, so a release without one
     * is refused outright. The full threat model — host allowlist, checksum,
     * Ed25519-signed checksum — lives on ReleaseArchive. Then version, disk
     * space and writability, established before anything is written; the
     * version guard is here and not only in CoreUpdateJob because the
     * stalled-worker fallback calls apply() straight from the admin's poll.
     */
    private function refusal(string $target, ?string $expectedSha256, ?string $signature, UpdateMode $mode, bool $requireSignature): ?string
    {
        if (! is_string($expectedSha256) || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            return $mode === UpdateMode::Update
                ? 'This release has no verified checksum from Update Manager — refusing to apply it. If this persists, the update server may need attention.'
                : 'A repair needs the SHA-256 published for the archive (64 hex characters) — refusing to apply an unverified archive.';
        }

        if ($requireSignature) {
            $signatureError = $this->archive->checkChecksumSignature($expectedSha256, $signature, $target);

            if ($signatureError !== null) {
                return $signatureError;
            }
        }

        return $this->preflight->firstBlocking($this->preflight->check($target, $mode))?->message;
    }

    /**
     * Obtain, verify, extract, plan, then apply by whichever tier the release
     * allows. Everything that can refuse does so before a file is touched.
     *
     * @param  Closure(): array{0: string, 1: bool}  $obtainArchive  the archive's path, and whether it is ours to delete afterwards
     */
    private function execute(UpdateMode $mode, string $target, Closure $obtainArchive, string $expectedSha256, bool $force, ?string $maintenanceSecret): CoreUpdateState
    {
        $lock = Cache::lock(self::LOCK_KEY, 1800);
        if (! $lock->get()) {
            $this->setProgress(CoreUpdateState::Queued, 'Another update is already in progress…', 0);

            return CoreUpdateState::Queued;
        }

        $backupPath = null;
        $zipPath = null;
        $disposable = false;
        $extractPath = null;
        $leaveMaintenanceOnExit = false;

        try {
            $this->housekeeping->pruneTemp();
            $this->housekeeping->pruneFailedCopies();

            $incompatible = $mode === UpdateMode::Update ? $this->checkCompatibility($target) : [];
            if ($incompatible !== [] && ! $force) {
                $names = array_map(static fn (IncompatiblePlugin $p): string => $p->displayName, $incompatible);

                return $this->fail('These enabled plugins are not compatible with v'.$target.': '.implode(', ', $names).'. Disable them first or wait for updated versions.');
            }

            $this->setProgress(CoreUpdateState::Running, $mode === UpdateMode::Update ? 'Downloading release…' : 'Reading the archive…', 12);
            [$zipPath, $disposable] = $obtainArchive();

            $this->setProgress(CoreUpdateState::Running, 'Verifying archive checksum…', 45);
            $this->archive->verifyChecksum($zipPath, $expectedSha256);

            $this->setProgress(CoreUpdateState::Running, 'Extracting…', 55);
            $extractPath = $this->archive->extract($zipPath);

            $plan = $this->planner->plan($extractPath, $target, MagnaServiceProvider::VERSION, $mode);
            $this->runLog->line($this->planner->describe($plan));

            if ($plan->manifest !== null && $this->engines->supports($plan->manifest)) {
                return $this->handOff($extractPath, $plan, $target, $mode, $maintenanceSecret, $force ? $incompatible : [], $leaveMaintenanceOnExit);
            }

            return $this->applyInProcess($extractPath, $plan, $target, $mode, $maintenanceSecret, $force ? $incompatible : [], $backupPath, $leaveMaintenanceOnExit);
        } catch (Throwable $e) {
            // Download, checksum, extraction, the manifest, the snapshot or
            // `down` itself threw: nothing from the new release has touched the
            // live tree. The restore is a formality here, and guarded — it used
            // to be the one unguarded call in the class, escaping apply() with
            // the lock released and nothing left to lift maintenance mode.
            if ($backupPath !== null) {
                try {
                    $this->snapshot->restore($backupPath);
                } catch (Throwable $restoreError) {
                    return $this->fail(
                        "Update failed before any files were changed: {$e->getMessage()} — and then the precautionary restore could not run: {$restoreError->getMessage()}. "
                        ."Nothing from the new release was applied. A copy of your files is in {$backupPath}."
                    );
                }
            }

            return $this->fail('Update failed before any files were changed: '.$e->getMessage());
        } finally {
            // Every exit lifts maintenance mode — except a hand-off, where
            // the new code lifts it once it has finished.
            if ($leaveMaintenanceOnExit) {
                $this->maintenance->leave();
            }

            // The download and its extraction are disposable whatever
            // happened; an operator's own archive is not ours to remove.
            try {
                $this->archive->cleanup($disposable ? $zipPath : null, $extractPath);
            } catch (Throwable $cleanupError) {
                $this->runLog->line('Could not remove temporary files: '.$cleanupError->getMessage());
            }

            $lock->release();
        }
    }

    /**
     * Tier A: the release's own engine performs the switch under this
     * process's guard, then this process steps back for the new code.
     *
     * @param  list<IncompatiblePlugin>  $toDisable  plugins a forced update leaves for the finalizer to disable
     */
    private function handOff(string $extractPath, ReleasePlan $plan, string $target, UpdateMode $mode, ?string $secret, array $toDisable, bool &$leaveMaintenanceOnExit): CoreUpdateState
    {
        $manifest = $plan->manifest;

        if ($manifest === null) {
            throw new RuntimeException('A hand-off needs a manifest.');
        }

        $engine = $this->engines->load($extractPath, $manifest);

        $journal = UpdateJournal::create($this->paths, (string) $this->runLog->runId(), [
            'mode' => $mode->value,
            'tier' => 'engine',
            'engine_api' => $manifest->engineApi,
            'from' => MagnaServiceProvider::VERSION,
            'to' => ltrim($target, 'vV'),
            'secret' => $secret,
            'manifest_sha256' => $manifest->sha256,
            'removed_classes' => $manifest->removedClasses,
            'check_files' => $manifest->checkFiles,
            'check_classes' => $manifest->checkClasses,
            'disable_plugins' => array_map(static fn (IncompatiblePlugin $p): string => $p->name, $toDisable),
            'caches' => [
                'config' => is_file($this->paths->base('bootstrap/cache/config.php')),
                'routes' => (glob($this->paths->base('bootstrap/cache/routes-*.php')) ?: []) !== [],
            ],
        ]);
        $journal->transition(RunState::Planned, ['plan' => ['paths' => $plan->paths, 'removed' => $plan->removedPaths]]);

        $context = new EngineContext(
            new StagedSwap($this->files, $this->paths, $this->guard, $journal),
            $journal,
            $plan,
            $extractPath,
            $this->paths,
            $this->files,
            $this->footprint,
            $this->composer,
            MagnaServiceProvider::VERSION,
            ltrim($target, 'vV'),
            $mode,
            function () use ($secret, &$leaveMaintenanceOnExit): void {
                $this->maintenance->enter($secret);
                $leaveMaintenanceOnExit = true;
            },
            fn (string $message, int $percent) => $this->setProgress(CoreUpdateState::Running, $message, $percent),
            fn (string $line) => $this->runLog->line($line),
        );

        try {
            $engine($context);

            if ($journal->state() !== RunState::FinalizePending) {
                throw new RuntimeException('the release engine returned without completing the switch.');
            }
        } catch (Throwable $e) {
            $this->runLog->line('The release engine failed: '.$e->getMessage());

            if (! $context->isSwitching()) {
                // Nothing went live; drop what was staged and report it as such.
                $context->rollback();
                $journal->transition(RunState::Failed, ['outcome' => $e->getMessage()]);

                return $this->fail('Update failed before any files were changed: '.$e->getMessage());
            }

            $this->rollback->rollBack($journal, $e->getMessage());
            $leaveMaintenanceOnExit = false;

            return CoreUpdateState::Failed;
        }

        // The switch is done. From here the NEW code owns the run: this
        // process must not touch the tree again, not even to lift maintenance
        // mode — that is the finalizer's last act, once the new code has
        // proven itself. Workers are told to exit and opcache to forget.
        $leaveMaintenanceOnExit = false;
        $this->rollback->armBootGuard($journal);
        $this->refresh->restartWorkers();
        $this->refresh->resetOpcache();
        $this->setProgress(CoreUpdateState::Switched, "Switched to v{$target} — finishing under the new release…", 80);

        return CoreUpdateState::Switched;
    }

    /**
     * Tiers B and C: this process lays the release down and finishes it, as
     * every update before engines did.
     *
     * @param  list<IncompatiblePlugin>  $toDisable
     *
     * @param-out string $backupPath
     */
    private function applyInProcess(string $extractPath, ReleasePlan $plan, string $target, UpdateMode $mode, ?string $secret, array $toDisable, ?string &$backupPath, bool &$leaveMaintenanceOnExit): CoreUpdateState
    {
        // After the archive is proven, not before: a bad download used to
        // leave a full snapshot behind for nothing.
        $this->setProgress(CoreUpdateState::Running, 'Backing up current files…', 62);
        $backupPath = $this->snapshot->create($plan->snapshotPaths());

        $leaveMaintenanceOnExit = true;
        $this->maintenance->enter($secret);

        $disableResult = null;

        try {
            $this->setProgress(CoreUpdateState::Running, 'Applying update…', 70);
            $delivered = $this->overlay->apply($extractPath, $plan);

            $this->setProgress(CoreUpdateState::Running, 'Running migrations…', 85);
            Artisan::call('migrate', ['--force' => true]);

            // A forced update may leave plugins enabled that are known-incompatible
            // with the target. Booting one on the next request could throw during
            // Laravel's own bootstrap and take the whole panel down with it — so they
            // are disabled here, still inside maintenance mode, before the site comes
            // back up. Data/config are preserved; the admin re-enables once updated.
            if ($toDisable !== []) {
                $this->setProgress(CoreUpdateState::Running, 'Disabling incompatible plugins…', 92);
                $disableResult = $this->compatibility->disable($toDisable);
            }

            $this->setProgress(CoreUpdateState::Running, 'Clearing caches…', 96);
            $this->refresh->refresh();

            $this->recordDelivery($target, $mode, $plan, $delivered);
        } catch (Throwable $e) {
            $this->setProgress(CoreUpdateState::Running, 'Update failed mid-apply — restoring previous files…', 50);

            // The restore can fail for the same reason the overlay did (a
            // path PHP cannot write). Letting that escape replaced a
            // readable failure with a raw stack trace, at the one moment the
            // admin most needs to be told what state their install is in.
            try {
                $this->snapshot->restore($backupPath);
            } catch (Throwable $restoreError) {
                Artisan::call('config:clear');

                return $this->fail(
                    "Update failed AND the rollback could not finish: {$e->getMessage()} — then: {$restoreError->getMessage()}. "
                    ."Your core files are now a mix of the old and new version. Re-upload the release archive over this install (src/Magna, app, bootstrap, routes, database/migrations), run `php artisan migrate --force`, and clear the caches. A copy of the previous files is in {$backupPath}."
                );
            }

            Artisan::call('config:clear');

            return $this->fail("Update failed and files were restored: {$e->getMessage()}. If migrations ran before the failure, your database may be ahead of the restored code — check your own DB backup before continuing.");
        }

        $this->pruneBackups();

        $message = $mode === UpdateMode::Repair ? "Repaired v{$target}." : "Updated to v{$target}.";
        $this->setProgress(CoreUpdateState::Completed, $disableResult?->describe($message) ?? $message, 100);

        return CoreUpdateState::Completed;
    }

    /**
     * Write down what was delivered, and tell the next boot which classmap
     * entries the release retired so it need not scan for them. Neither may
     * fail the update they describe: it has already happened.
     *
     * @param  list<string>  $delivered  the paths the archive actually carried and the overlay laid down
     */
    private function recordDelivery(string $target, UpdateMode $mode, ReleasePlan $plan, array $delivered): void
    {
        try {
            if (! $this->footprint->record($target, $mode->value, $this->runLog->runId(), $plan->manifest, $delivered)) {
                $this->runLog->line('Could not write '.InstalledFootprint::FILENAME.'; the next boot will report the update as unrecorded.');
            }

            if ($plan->manifest !== null && $plan->manifest->removedClasses !== []) {
                (new StaleClassMap(
                    $this->paths->storage('framework/cache/magna-stale-classmap.json'),
                    $this->paths->base('vendor/composer/autoload_classmap.php'),
                    ltrim($target, 'vV'),
                ))->prime($plan->manifest->removedClasses);
            }
        } catch (Throwable $e) {
            $this->runLog->line('Could not record the delivery: '.$e->getMessage());
        }
    }

    /** After a success only: the snapshot just taken and the one before it stay. Never fatal. */
    private function pruneBackups(): void
    {
        try {
            $removed = $this->housekeeping->pruneBackups();

            if ($removed !== []) {
                $this->runLog->line('Removed older backups: '.implode(', ', $removed));
            }
        } catch (Throwable $e) {
            $this->runLog->line('Could not prune older backups: '.$e->getMessage());
        }
    }

    private function fail(string $message): CoreUpdateState
    {
        $this->setProgress(CoreUpdateState::Failed, $message, 100);

        return CoreUpdateState::Failed;
    }

    /**
     * @param  int  $percent  roughly how far along the apply is, so the UI can
     *                        draw a bar rather than only name the current step.
     *                        Approximate on purpose: the download dominates and
     *                        its size is not known until it starts.
     */
    private function setProgress(CoreUpdateState $state, string $message, int $percent = 0): void
    {
        CoreUpdateProgress::set($state, $message, $percent, $this->targetVersion);
        $this->runLog->line(ucfirst($state->value).' '.$percent.'% — '.$message);
    }
}
