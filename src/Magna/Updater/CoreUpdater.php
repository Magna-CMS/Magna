<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\OctaneServiceProvider;
use Magna\Licensing\PackageExtractor;
use Magna\Licensing\SignedPayload;
use Magna\Plugins\PluginInfo;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * Applies a published core release: downloads the pre-built archive, overlays
 * core-owned paths, migrates, clears caches, and reloads Octane. Modeled on
 * Magna\Marketplace\PluginInstaller — same lock-and-poll shape, same
 * fail-with-message pattern, progress written to cache for the UI to read.
 *
 * Scope note: this overlays source code only (src/Magna, app, bootstrap,
 * routes, database/migrations) — never composer.json/composer.lock/vendor/.
 * A customer's vendor/ may contain plugin packages added by
 * PluginInstaller's own `composer require` calls that the core release's
 * composer.json knows nothing about; overlaying it wholesale would silently
 * drop them. A core release that changes its own Composer dependencies is
 * therefore not yet a "one-click" case — see docs/updates-architecture.md.
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
    ];

    /**
     * `$zipUrl` comes straight from Update Manager's `/updates` response
     * (see UpdateEntry::$downloadUrl / UpdateCheckClient) with no signature
     * or checksum on the archive itself — this overlay is the single
     * highest-blast-radius operation in the app (it replaces `app/`,
     * `bootstrap/`, and `src/Magna` — i.e. the code that runs on every
     * request — on every connected install that clicks "Update Now"). A
     * compromised or MITM'd response could otherwise point this at an
     * arbitrary host. The allowlist alone was a floor, not a full fix — it
     * stopped an attacker-supplied arbitrary host, but didn't verify archive
     * integrity. `$expectedSha256` closes that: Update Manager's `/updates`
     * response now must include `zip_sha256` (see `UpdateEntry::$downloadSha256`
     * / `UpdateEntry::fromArray()`), and `apply()` refuses to proceed without
     * a valid-looking one — fail-closed, not "verify if present."
     *
     * The checksum and the URL still travel together, so a hostile update
     * server forges both — which is what `$checksumSignature` and
     * `checkChecksumSignature()` exist for.
     *
     * @var list<string>
     */
    private const ALLOWED_DOWNLOAD_HOSTS = [
        'managemagna.jrstudios.dev',
        'github.com',
        'objects.githubusercontent.com',
        'codeload.github.com',
    ];

    /** Version being applied, so every progress line can name it ("v1.3.4 — Downloading release…"). */
    private ?string $targetVersion = null;

    public function __construct(
        private readonly PluginManager $plugins,
        private readonly Filesystem $files,
        private readonly PackageExtractor $extractor,
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

        if (! is_string($expectedSha256) || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            return $this->fail('This release has no verified checksum from Update Manager — refusing to apply it. If this persists, the update server may need attention.');
        }

        $signatureError = $this->checkChecksumSignature($expectedSha256, $checksumSignature);
        if ($signatureError !== null) {
            return $this->fail($signatureError);
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
            $backupPath = $this->backup();

            $this->setProgress(CoreUpdateState::Running, 'Downloading release…', 20);
            $zipPath = $this->download($zipUrl);

            $this->setProgress(CoreUpdateState::Running, 'Verifying archive checksum…', 55);
            $this->verifyChecksum($zipPath, $expectedSha256);

            $this->setProgress(CoreUpdateState::Running, 'Extracting…', 65);
            $extractPath = $this->extract($zipPath);

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
                $this->restore($backupPath);
                Artisan::call('config:clear');

                return $this->fail("Update failed and files were restored: {$e->getMessage()}. If migrations ran before the failure, your database may be ahead of the restored code — check your own DB backup before continuing.");
            } finally {
                Artisan::call('up');
            }

            $this->cleanup($zipPath, $extractPath);

            $message = $this->buildSuccessMessage($targetVersion, $disableResult);
            $this->setProgress(CoreUpdateState::Completed, $message, 100);

            return CoreUpdateState::Completed;
        } catch (Throwable $e) {
            if ($backupPath !== null) {
                $this->restore($backupPath);
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

        if (class_exists(OctaneServiceProvider::class) && filter_var(getenv('LARAVEL_OCTANE'), FILTER_VALIDATE_BOOLEAN)) {
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

    /** Snapshot the core-owned paths to a timestamped backup directory, for file-level rollback. */
    private function backup(): string
    {
        $backupPath = storage_path('app/magna-updates/backups/'.now()->format('Y_m_d_His'));

        foreach (self::CORE_OWNED_PATHS as $relative) {
            $source = base_path($relative);
            if (! is_dir($source) && ! is_file($source)) {
                continue;
            }
            $this->files->mirror($source, $backupPath.'/'.$relative, null, ['override' => true]);
        }

        return $backupPath;
    }

    private function restore(string $backupPath): void
    {
        foreach (self::CORE_OWNED_PATHS as $relative) {
            $source = $backupPath.'/'.$relative;
            if (! is_dir($source) && ! is_file($source)) {
                continue;
            }
            $this->files->mirror($source, base_path($relative), null, ['override' => true, 'delete' => true]);
        }
    }

    private function guardDownloadUrl(string $zipUrl): void
    {
        $scheme = parse_url($zipUrl, PHP_URL_SCHEME);
        $host = parse_url($zipUrl, PHP_URL_HOST);

        if ($scheme !== 'https' || ! is_string($host) || ! in_array(strtolower($host), self::ALLOWED_DOWNLOAD_HOSTS, true)) {
            throw new \RuntimeException("Refusing to download a core update from an untrusted source: {$zipUrl}");
        }
    }

    private function download(string $zipUrl): string
    {
        $this->guardDownloadUrl($zipUrl);

        $tmpDir = storage_path('app/magna-updates/tmp');
        $this->files->mkdir($tmpDir);
        $zipPath = $tmpDir.'/core-'.uniqid().'.zip';

        $response = Http::timeout(300)->sink($zipPath)->get($zipUrl);
        if (! $response->successful()) {
            throw new \RuntimeException("Could not download the release archive (HTTP {$response->status()}).");
        }

        return $zipPath;
    }

    /**
     * The checksum defeats an attacker who can swap the archive. It does NOT
     * defeat one who controls the `/updates` response itself, because the URL
     * and the checksum arrive together over the same channel — whoever forges
     * one forges both, and the payload lands on code that runs every request.
     *
     * The Ed25519 signature closes that: it is minted by the marketplace's
     * private key, which never leaves the marketplace, and verified against
     * the public key baked into this build — the same control licensed plugin
     * downloads already use (Magna\Licensing\LicenseInstaller).
     *
     * A present-but-invalid signature is always fatal. A *missing* one is
     * fatal only when `magna.updater.require_signed_checksum` is on, because
     * Update Manager has to publish `zip_sha256_signature` for every release
     * before that can be enforced without bricking updates. Flip the flag as
     * soon as it does — until then this is checksum-only against a hostile
     * update server.
     */
    private function checkChecksumSignature(string $expectedSha256, ?string $signature): ?string
    {
        $required = (bool) config('magna.updater.require_signed_checksum', false);

        if ($signature === null || $signature === '') {
            if ($required) {
                return 'This release was published without a signed checksum — refusing to apply it.';
            }

            Log::warning('Core update applied with an unsigned checksum; the update server has not published zip_sha256_signature.', [
                'sha256' => $expectedSha256,
            ]);

            return null;
        }

        if (! SignedPayload::verify($signature, SignedPayload::canonicalize(['sha256' => $expectedSha256]))) {
            Log::critical('Core update refused: the release checksum failed Ed25519 signature verification.', [
                'sha256' => $expectedSha256,
            ]);

            return 'The release checksum failed signature verification — refusing to apply it. This can mean the update response was tampered with.';
        }

        return null;
    }

    /** @throws \RuntimeException if the downloaded archive doesn't match the checksum Update Manager published for it. */
    private function verifyChecksum(string $zipPath, string $expectedSha256): void
    {
        $actual = hash_file('sha256', $zipPath);

        if (! is_string($actual) || ! hash_equals($expectedSha256, $actual)) {
            throw new \RuntimeException(
                "Downloaded archive checksum does not match — expected {$expectedSha256}, got ".($actual ?: 'unreadable').
                '. The archive will not be applied.'
            );
        }
    }

    /**
     * Extraction goes through the same PackageExtractor licensed plugin
     * installs use — entry-name validation, symlink rejection, and an
     * uncompressed-size ceiling, all applied before a byte is written.
     *
     * A core archive is a strictly higher-value target than a plugin package
     * (it lands on `bootstrap/` and `src/Magna`), so it must not have weaker
     * structural checks than one. It previously called `extractTo()` directly
     * with none of them.
     */
    private function extract(string $zipPath): string
    {
        $extractPath = storage_path('app/magna-updates/tmp/extract-'.uniqid());

        $this->extractor->extract($zipPath, $extractPath);

        // GitHub-style archives wrap contents in a single top-level folder
        // (e.g. "Magna-<version>/") — descend into it if that's what we got.
        return $this->extractor->resolveContentRoot($extractPath);
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

    private function cleanup(string $zipPath, string $extractPath): void
    {
        $this->files->remove([$zipPath, $extractPath]);
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
