<?php

declare(strict_types=1);

namespace Magna\Marketplace;

use Illuminate\Support\Facades\Cache;
use Magna\MagnaServiceProvider;
use Magna\Plugins\PluginInfo;
use Magna\Plugins\PluginManager;
use Throwable;

/**
 * Installs a marketplace plugin: re-verifies it is an approved, compatible
 * package, runs `composer require`, enables it, and rolls back on failure.
 * Progress is written to the cache so the UI can poll it.
 */
class PluginInstaller
{
    private const LOCK_KEY = 'magna.marketplace.install.lock';

    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly PluginManager $plugins,
        private readonly ComposerRunner $composer,
    ) {}

    public function install(string $package): InstallState
    {
        $this->setProgress($package, InstallState::Running, 'Starting…');

        if (! $this->composer->isAvailable()) {
            return $this->fail($package, 'Composer is not available on this server, so plugins cannot be installed automatically.');
        }

        // Single install at a time — a plugin install mutates the whole app's
        // dependency tree, so two must never run concurrently. If another install
        // holds the lock, report Queued so the job re-queues and waits its turn.
        $lock = Cache::lock(self::LOCK_KEY, 900);
        if (! $lock->get()) {
            $this->setProgress($package, InstallState::Queued, 'Waiting for another installation to finish…');

            return InstallState::Queued;
        }

        try {
            // Trust boundary: only ever install a package the marketplace lists
            // as approved — never a name that merely arrived from the browser.
            $listing = $this->marketplace->plugin($package);
            if ($listing === null) {
                return $this->fail($package, "\"{$package}\" is not an approved marketplace plugin.");
            }

            if (! $listing->isCompatibleWith(MagnaServiceProvider::VERSION)) {
                return $this->fail($package, "\"{$package}\" is not compatible with this version of Magna.");
            }

            // Stage 6: pin the exact version the marketplace reviewed and
            // approved — an unpinned `composer require {package}` resolves
            // whatever the latest compatible release is *at install time*,
            // which may be a newer, unreviewed version pushed after
            // approval (the marketplace's per-plugin `status` column, not
            // per-version, means a new version is visible in the catalog
            // the moment PluginSubmissions::submitNewVersion() resets
            // status back to 'submitted' — but nothing stops a version row
            // being added by some future code path that skips that reset).
            // Pinning here means "install" always installs precisely the
            // version this trust-boundary check just verified is approved.
            $this->setProgress($package, InstallState::Running, 'Downloading…');
            $required = $this->composer->run(['require', $package.':'.$listing->version], 600);
            if (! $required->successful()) {
                return $this->fail($package, "Composer could not install the plugin.\n".$required->output);
            }

            $manifestMismatch = $this->verifyInstalledManifest($package, $listing->version);
            if ($manifestMismatch !== null) {
                return $manifestMismatch;
            }

            $this->setProgress($package, InstallState::Running, 'Enabling…');
            try {
                $this->plugins->enable($package);
            } catch (Throwable $e) {
                // Roll the failed install back out of the dependency tree.
                $this->composer->run(['remove', $package], 600);

                return $this->fail($package, 'The plugin was installed but failed to enable, and has been rolled back: '.$e->getMessage());
            }

            return $this->finishSuccess($package);
        } finally {
            $lock->release();
        }
    }

    /**
     * Defense in depth: verify the manifest Composer actually installed
     * matches what was reviewed, in case dependency resolution or a
     * misconfigured repository substituted something else despite the
     * version pin in install(). Returns a failure state if it doesn't
     * match (and rolls the install back), or null if it's clean.
     *
     * Versions are compared ignoring a leading "v": Composer tags releases
     * as vX.Y.Z and the catalog stores that tag, while manifests carry the
     * bare number — the marketplace's own docs plugin shipped as tag v1.1.0
     * with manifest 1.1.0, and the mismatch rolled back every install of it.
     * The failure message names what was actually found, because "did not
     * match" cost a debugging session that "expected v1.1.0, found manifest
     * magna/docs 1.1.0" would not have.
     */
    private function verifyInstalledManifest(string $package, string $approvedVersion): ?InstallState
    {
        $installedInfo = $this->findInstalled($package);

        if ($installedInfo !== null
            && ltrim($installedInfo->manifest->version, 'vV') === ltrim($approvedVersion, 'vV')) {
            return null;
        }

        $this->composer->run(['remove', $package], 600);

        $found = $installedInfo === null
            ? "no discovered plugin names \"{$package}\" in its manifest"
            : "found manifest {$installedInfo->manifest->name} {$installedInfo->manifest->version}";

        return $this->fail(
            $package,
            "The installed plugin did not match the approved listing and was rolled back: expected \"{$package}\" at {$approvedVersion}, {$found}.",
        );
    }

    private function finishSuccess(string $package): InstallState
    {
        InstallProgress::clearPending($package);
        $this->marketplace->clearCache();
        $this->marketplace->reportInstall($package);
        $this->setProgress($package, InstallState::Completed, 'Installed.');

        return InstallState::Completed;
    }

    /**
     * Current install progress for a package.
     *
     * @return array{state: string|null, message: string, waiting_seconds: int}
     */
    public static function progress(string $package): array
    {
        return InstallProgress::read($package);
    }

    private function findInstalled(string $package): ?PluginInfo
    {
        foreach ($this->plugins->discover() as $info) {
            if ($info->manifest->name === $package) {
                return $info;
            }
        }

        return null;
    }

    private function fail(string $package, string $message): InstallState
    {
        InstallProgress::clearPending($package);
        $this->setProgress($package, InstallState::Failed, $message);

        return InstallState::Failed;
    }

    private function setProgress(string $package, InstallState $state, string $message): void
    {
        InstallProgress::set($package, $state, $message);
    }
}
