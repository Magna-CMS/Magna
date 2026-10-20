<?php

declare(strict_types=1);

namespace Magna\Marketplace;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Magna\Admin\AdminAssetPublisher;
use Magna\Admin\Notifications\NotificationRecipients;
use Magna\MagnaServiceProvider;
use Magna\Plugins\PluginDiscovery;
use Magna\Plugins\PluginInfo;
use Magna\Plugins\PluginManager;
use Magna\Support\Version;
use Magna\Updater\InstalledVersionRecorder;
use Throwable;

/**
 * Installs or updates a marketplace plugin: re-verifies it is an approved,
 * compatible package, runs `composer require`, enables it, and rolls back on
 * failure. Progress is written to the cache so the UI can poll it.
 *
 * Install and update are one pipeline differing in exactly two places, both of
 * which matter: an update rolls a failure back to the version that was working
 * a minute ago rather than removing the plugin outright, and it does not report
 * a new install to the marketplace's adoption counter.
 */
class PluginInstaller
{
    private const LOCK_KEY = 'magna.marketplace.install.lock';

    /** Which kind of run is in progress, so every failure path words itself right. */
    private bool $runningUpdate = false;

    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly PluginManager $plugins,
        private readonly ComposerRunner $composer,
        private readonly PluginDiscovery $discovery,
        private readonly InstalledVersionRecorder $versions = new InstalledVersionRecorder,
        private readonly AdminAssetPublisher $assets = new AdminAssetPublisher,
    ) {}

    public function install(string $package): InstallState
    {
        return $this->run($package, update: false);
    }

    /**
     * Fetch and install the version the marketplace now advertises.
     *
     * The free-plugin update path used to be PluginManager::enable(), which
     * re-reads what is already on disk: for a plugin delivered by Composer the
     * newer version was still on Packagist, so the panel reported success and
     * changed nothing. An update is an install of a newer pinned version.
     */
    public function update(string $package): InstallState
    {
        return $this->run($package, update: true);
    }

    private function run(string $package, bool $update): InstallState
    {
        $this->runningUpdate = $update;
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
            // Read BEFORE Composer touches the tree: on an update this is the
            // version to go back to, and after `require` nothing records it.
            $previous = $update ? $this->findInstalled($package)?->manifest->version : null;

            $this->setProgress($package, InstallState::Running, 'Downloading…');
            $required = $this->changeTree(['require', $package.':'.$listing->version]);
            if (! $required->successful()) {
                return $this->fail(
                    $package,
                    "Composer could not install the plugin.\n"
                    .$this->explainStaleListing($package, $listing->version, $required->output)
                    .$required->output,
                );
            }

            $manifestMismatch = $this->verifyInstalledManifest($package, $listing->version, $previous);
            if ($manifestMismatch !== null) {
                return $manifestMismatch;
            }

            $this->setProgress($package, InstallState::Running, 'Enabling…');
            try {
                $this->plugins->enable($package);
            } catch (Throwable $e) {
                $this->rollBack($package, $previous);

                return $this->fail(
                    $package,
                    'The plugin was '.($update ? 'updated' : 'installed')
                    .' but failed to enable, and has been rolled back: '.$e->getMessage(),
                );
            }

            return $this->finishSuccess($package, $listing->version, $update);
        } catch (Throwable $e) {
            // Anything thrown above (the catalog call, Composer itself) used to
            // escape with progress frozen at "Downloading…" and nothing told
            // anyone. It is a failed run like any other.
            report($e);

            return $this->fail($package, 'The '.($update ? 'update' : 'installation').' stopped unexpectedly: '.$e->getMessage());
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
    private function verifyInstalledManifest(string $package, string $approvedVersion, ?string $previousVersion): ?InstallState
    {
        $installedInfo = $this->findInstalled($package);

        if ($installedInfo !== null
            && ltrim($installedInfo->manifest->version, 'vV') === ltrim($approvedVersion, 'vV')) {
            return null;
        }

        $this->rollBack($package, $previousVersion);

        $found = $installedInfo === null
            ? "no discovered plugin names \"{$package}\" in its manifest"
            : "found manifest {$installedInfo->manifest->name} {$installedInfo->manifest->version}";

        return $this->fail(
            $package,
            "The installed plugin did not match the approved listing and was rolled back: expected \"{$package}\" at {$approvedVersion}, {$found}.",
        );
    }

    /**
     * Undo a failed run.
     *
     * Removing the package is right for an install — it was not there before —
     * and wrong for an update: the site had a working plugin a minute ago, and
     * uninstalling it would be a worse outage than the failed update. Pinning
     * the previous version back puts the tree where it started.
     */
    private function rollBack(string $package, ?string $previousVersion): void
    {
        $previousVersion === null
            ? $this->changeTree(['remove', $package])
            : $this->changeTree(['require', $package.':'.$previousVersion]);
    }

    /**
     * Run a Composer command that changes what is installed, then forget the
     * memoized plugin scan.
     *
     * PluginDiscovery memoizes its scan, and it is a container singleton. An
     * update reads the installed version BEFORE Composer runs — it is the
     * version a failure rolls back to — which primed that memo with the old
     * manifest. The post-install manifest check, and enable() after it, then
     * saw the release Composer had just replaced: every update of a
     * Composer-delivered plugin failed "expected v1.1.0, found manifest
     * 1.0.0" and rolled itself back, while installs worked only because
     * nothing had scanned first. A queue worker outlives one job, so a stale
     * memo would also have leaked into the next.
     *
     * @param  list<string>  $args
     */
    private function changeTree(array $args): ComposerResult
    {
        $result = $this->composer->run($args, 600);
        $this->discovery->reset();

        return $result;
    }

    private function finishSuccess(string $package, string $installedVersion, bool $update = false): InstallState
    {
        InstallProgress::clearPending($package);
        $this->marketplace->clearCache();

        // An update is not a new adoption: counting it would inflate the
        // marketplace's install numbers every time a site keeps itself current.
        if (! $update) {
            $this->marketplace->reportInstall($package);
        }

        // Without this the Plugins page and the dashboard badge went on offering
        // the version just installed, because they read the check-in row and
        // nothing updated it until the next 12-hourly check.
        $this->versions->record($package, $installedVersion);

        $this->publishAssets($package);

        $this->setProgress($package, InstallState::Completed, $update ? 'Updated.' : 'Installed.');

        $this->tellAdmins(
            $update ? "{$package} updated to ".Version::label($installedVersion) : "{$package} installed",
            $update ? 'The new version is live.' : 'The plugin is installed and enabled.',
            'success',
        );

        return InstallState::Completed;
    }

    /**
     * Installs and updates run in a queue worker, out of sight of the page
     * that asked for them. The bell is where the outcome survives a closed
     * tab — a failed update used to leave no trace an admin would ever see.
     */
    private function tellAdmins(string $title, string $body, string $status): void
    {
        try {
            NotificationRecipients::notifyDashboard($title, $body, $status);
        } catch (Throwable) {
            // Best-effort: the progress record and the log carry the outcome.
        }
    }

    /**
     * Copy the plugin's admin assets into public/.
     *
     * Nothing did after a Composer install or update: on a site whose plugins
     * all arrived through the marketplace, the blog's editor script 404'd, its
     * post editor showed a title field and no body, and no post could be
     * written. Run after enable(), when the plugin has booted in this process
     * and registered its assets; an update re-copies the files Composer just
     * replaced.
     *
     * The plugin is installed and enabled by now, so a failed copy is logged,
     * never turned into a failed install.
     */
    private function publishAssets(string $package): void
    {
        try {
            $this->assets->publish();
        } catch (Throwable $e) {
            Log::warning('Plugin installed, but its admin assets could not be published.', [
                'package' => $package,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Current install progress for a package.
     *
     * @return array{state: string|null, message: string, waiting_seconds: int, kind: string}
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

    /**
     * A plain sentence in front of Composer's resolver output when the failure
     * is that the marketplace pinned a version the package no longer publishes.
     *
     * magna-cms/docs was approved at v1.1.0, the tag was later replaced by
     * v1.1.1, and from then on every install of it failed here. Composer says
     * so accurately — "requires magna-cms/docs v1.1.0 (exact version match),
     * found magna-cms/docs[dev-main, v1.1.1, …]" — but only to somebody who
     * already knows the catalog pins the approved version, so the panel read as
     * a broken installer rather than a stale listing. Read out of Composer's own
     * output: no extra network call in a path that has already failed.
     */
    private function explainStaleListing(string $package, string $version, string $output): string
    {
        if (! str_contains($output, 'exact version match') || ! str_contains($output, 'does not match the constraint')) {
            return '';
        }

        $found = preg_match('/found\s+'.preg_quote($package, '/').'\[([^\]]+)\]/', $output, $m) === 1
            ? ' It currently publishes: '.$m[1].'.'
            : '';

        return "The marketplace lists {$version}, which {$package} no longer publishes.{$found}"
            ." Ask the developer to submit the current release — the listing cannot install until it names a published version.\n\n";
    }

    private function fail(string $package, string $message): InstallState
    {
        InstallProgress::clearPending($package);
        $this->setProgress($package, InstallState::Failed, $message);

        $this->tellAdmins(
            "Couldn't ".($this->runningUpdate ? 'update' : 'install')." {$package}",
            $message,
            'danger',
        );

        return InstallState::Failed;
    }

    private function setProgress(string $package, InstallState $state, string $message): void
    {
        InstallProgress::set($package, $state, $message);
    }
}
