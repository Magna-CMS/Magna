<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\OctaneServiceProvider;
use Magna\Plugins\PluginInfo;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\Support\Runtime;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * Applies a published core release: downloads the pre-built archive, overlays
 * core-owned paths, migrates, clears caches, and reloads Octane. Modeled on
 * Magna\Marketplace\PluginInstaller — same lock-and-poll shape, same
 * fail-with-message pattern, progress written to cache for the UI to read.
 *
 * Scope note: this overlays source code only (src/Magna, app, bootstrap,
 * routes, database/migrations) plus the plugin SDK — never
 * composer.json/composer.lock, and never the rest of vendor/. A customer's
 * vendor/ may contain plugin packages added by PluginInstaller's own
 * `composer require` calls that the core release's composer.json knows
 * nothing about; overlaying it wholesale would silently drop them. A core
 * release that changes its own Composer dependencies is therefore not yet a
 * "one-click" case — see docs/updates-architecture.md.
 *
 * The SDK is the exception, and deliberately so: it is core's contract surface
 * that merely happens to live under vendor/. Shipping core without it meant a
 * plugin written against a new contract could not be enabled on an updated
 * site, because the interface it implements had never arrived.
 *
 * Also out of scope for the same reason: a generic, cross-driver DB
 * dump/restore. Rollback restores the file-level snapshot only; if
 * `migrate --force` fails partway, the site owner's own DB backup (which
 * they should always take before updating, same as before any migration)
 * is the recovery path.
 */
class CoreUpdater
{
    private const LOCK_KEY = 'magna.updater.apply.lock';

    /** @var list<string> */
    private const CORE_OWNED_PATHS = [
        'src/Magna',
        'app',
        'bootstrap',
        'routes',
        'database/migrations',
        // The panel's compiled assets and the fonts they name. Code arrived on
        // an update and these did not, which breaks in the least obvious way
        // available: a Blade partial referencing a font this release added
        // pointed at a file the update never delivered, the request 404'd, and
        // every icon in the admin rendered as its own ligature name —
        // "verified_user", "arrow_forward" — as plain text beside the
        // headings. Nothing errored; the panel simply looked broken, and only
        // on sites that had updated rather than installed fresh.
        //
        // Both directories are core's own build output: hashed asset bundles
        // and font files, no customer content. Deliberately not `public`
        // itself, which also holds uploads and the storage symlink and must
        // survive an update untouched.
        'public/build',
        'public/fonts',
        // The SDK travels with core, because it is core's own contract surface
        // under a vendor path rather than a third-party dependency. Leaving it
        // behind is what made a plugin built against a newer contract
        // uninstallable on an updated site: core arrived, the interface it
        // names did not, and enabling the plugin died with
        // `Interface "Magna\Contracts\…" not found`. Its namespaces are
        // registered at boot by PluginAutoloader, so a contract in a namespace
        // the site's vendor/composer maps predate still resolves.
        self::SDK_PATH,
        // A hub install resolves the SDK through a path repository rooted here,
        // copied (not symlinked) into vendor/ by Composer. Refreshing only the
        // vendor/ copy therefore lasts exactly until the next `composer require`
        // — which every marketplace plugin install runs — and that re-copies the
        // stale source straight back over it, reviving the missing-interface
        // failure the SDK overlay exists to prevent. A core-only release carries
        // no bundled/ directory, and overlay() skips paths the archive does not
        // contain, so listing it here is a no-op outside a hub.
        self::SDK_SOURCE_PATH,
        // `resources/` is deliberately NOT here, and core keeps nothing at
        // runtime inside it. Three render-hook partials were added under
        // resources/views in 1.4.0; the code that renders them shipped with
        // src/Magna and the views did not, so every updated site answered 500
        // on every admin page — "View [filament.magna.footer] not found" —
        // while a fresh install of the same release was perfect. Core's own
        // panel views now live in src/Magna/Admin/Resources/views under the
        // `magna::` namespace, and an architecture test keeps them there.
        // Overlaying resources/ instead would put core's hands on a directory
        // whose remaining contents are build inputs a site may legitimately
        // customise.
    ];

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
     * The paths a one-click update overlays. Public so the installer's
     * requirements screen and CoreWritability can report on the same list core
     * actually replaces, instead of keeping a second copy that drifts.
     *
     * @return list<string>
     */
    public static function coreOwnedPaths(): array
    {
        return self::CORE_OWNED_PATHS;
    }

    public function __construct(
        private readonly PluginManager $plugins,
        private readonly Filesystem $files,
        private readonly ReleaseArchive $archive,
        private readonly CoreSnapshot $snapshot,
    ) {}

    public function apply(
        string $targetVersion,
        string $zipUrl,
        ?string $expectedSha256,
        bool $force = false,
        ?string $checksumSignature = null,
    ): CoreUpdateState {
        $this->targetVersion = $targetVersion;
        $this->setProgress(CoreUpdateState::Running, 'Starting…', 2);

        // Fail-closed, not "verify if present": this overlay replaces the
        // code that runs on every request, so a release without a checksum
        // is refused outright. The full threat model — host allowlist,
        // checksum, Ed25519-signed checksum — lives on ReleaseArchive.
        if (! is_string($expectedSha256) || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            return $this->fail('This release has no verified checksum from Update Manager — refusing to apply it. If this persists, the update server may need attention.');
        }

        $signatureError = $this->archive->checkChecksumSignature($expectedSha256, $checksumSignature);
        if ($signatureError !== null) {
            return $this->fail($signatureError);
        }

        // Established before anything is written. A read-only src/Magna used to
        // surface halfway through the overlay, with the rollback failing for the
        // same reason and leaving the core tree mixed between two versions.
        $writability = new CoreWritability(base_path());
        $blocker = $writability->summary();
        if ($blocker !== null) {
            return $this->fail($blocker.'. A one-click update has to replace those files, so nothing was changed. '.($writability->remedy() ?? ''));
        }

        $lock = Cache::lock(self::LOCK_KEY, 1800);
        if (! $lock->get()) {
            $this->setProgress(CoreUpdateState::Queued, 'Another update is already in progress…', 0);

            return CoreUpdateState::Queued;
        }

        $backupPath = null;

        try {
            $incompatible = $this->checkCompatibility($targetVersion);
            if ($incompatible !== [] && ! $force) {
                $names = array_map(static fn (IncompatiblePlugin $p): string => $p->displayName, $incompatible);

                return $this->fail('These enabled plugins are not compatible with v'.$targetVersion.': '.implode(', ', $names).'. Disable them first or wait for updated versions.');
            }

            $this->setProgress(CoreUpdateState::Running, 'Backing up current files…', 8);
            $backupPath = $this->snapshot->create();

            $this->setProgress(CoreUpdateState::Running, 'Downloading release…', 20);
            $zipPath = $this->archive->download($zipUrl);

            $this->setProgress(CoreUpdateState::Running, 'Verifying archive checksum…', 55);
            $this->archive->verifyChecksum($zipPath, $expectedSha256);

            $this->setProgress(CoreUpdateState::Running, 'Extracting…', 65);
            $extractPath = $this->archive->extract($zipPath);

            Artisan::call('down');

            $disableResult = null;

            try {
                $this->setProgress(CoreUpdateState::Running, 'Applying update…', 78);
                $this->overlay($extractPath);

                $this->setProgress(CoreUpdateState::Running, 'Running migrations…', 88);
                Artisan::call('migrate', ['--force' => true]);

                // A forced update may leave plugins enabled that are known-incompatible
                // with $targetVersion. Booting one on the next request could throw during
                // Laravel's own bootstrap and take the whole panel down with it — so they
                // are disabled here, still inside maintenance mode, before the site comes
                // back up. Data/config are preserved; the admin re-enables once updated.
                if ($force && $incompatible !== []) {
                    $this->setProgress(CoreUpdateState::Running, 'Disabling incompatible plugins…', 93);
                    $disableResult = $this->disableIncompatiblePlugins($incompatible);
                }

                $this->setProgress(CoreUpdateState::Running, 'Clearing caches…', 96);
                $this->clearCachesAndReloadOctane();
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
            } finally {
                Artisan::call('up');
            }

            $this->archive->cleanup($zipPath, $extractPath);

            $message = $this->buildSuccessMessage($targetVersion, $disableResult);
            $this->setProgress(CoreUpdateState::Completed, $message, 100);

            return CoreUpdateState::Completed;
        } catch (Throwable $e) {
            if ($backupPath !== null) {
                $this->snapshot->restore($backupPath);
            }

            return $this->fail('Update failed before any files were changed: '.$e->getMessage());
        } finally {
            $lock->release();
        }
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
        $enabledNames = PluginRecord::query()->where('enabled', true)->pluck('name')->all();
        if ($enabledNames === []) {
            return [];
        }

        $incompatible = [];
        foreach ($this->plugins->discover() as $info) {
            /** @var PluginInfo $info */
            if (in_array($info->manifest->name, $enabledNames, true) && ! $info->manifest->isCompatibleWith($targetVersion)) {
                $incompatible[] = new IncompatiblePlugin(
                    name: $info->manifest->name,
                    displayName: $info->manifest->displayName,
                    installedVersion: $info->manifest->version,
                    requiredCompat: $info->manifest->magnaCompat,
                );
            }
        }

        return $incompatible;
    }

    /** @param  list<IncompatiblePlugin>  $incompatible */
    private function disableIncompatiblePlugins(array $incompatible): PluginDisableResult
    {
        $disabled = [];
        $failed = [];

        foreach ($incompatible as $plugin) {
            try {
                $this->plugins->disable($plugin->name);
                $disabled[] = $plugin->displayName;
            } catch (Throwable) {
                $failed[] = $plugin->displayName;
            }
        }

        return new PluginDisableResult($disabled, $failed);
    }

    private function clearCachesAndReloadOctane(): void
    {
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');

        if (class_exists(OctaneServiceProvider::class) && Runtime::isOctane()) {
            Artisan::call('octane:reload');
        }
    }

    private function buildSuccessMessage(string $targetVersion, ?PluginDisableResult $disableResult): string
    {
        $message = "Updated to v{$targetVersion}.";

        if ($disableResult === null) {
            return $message;
        }

        if ($disableResult->disabled !== []) {
            $message .= ' Automatically disabled (incompatible with this version): '.implode(', ', $disableResult->disabled).'.';
        }
        if ($disableResult->failed !== []) {
            $message .= ' WARNING: could not disable these incompatible plugins — disable them manually now: '.implode(', ', $disableResult->failed).'.';
        }

        return $message;
    }

    /** Replace only the core-owned paths — never composer.json/composer.lock/vendor/, never .env or storage/. */
    private function overlay(string $extractPath): void
    {
        foreach (self::CORE_OWNED_PATHS as $relative) {
            $source = $extractPath.'/'.$relative;
            if (! is_dir($source) && ! is_file($source)) {
                continue;
            }
            $this->files->mirror($source, base_path($relative), null, ['override' => true, 'delete' => true]);
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
    }
}
