<?php

declare(strict_types=1);

namespace Magna\Marketplace;

/**
 * Starts a marketplace install and keeps it from silently going nowhere.
 *
 * InstallPluginJob is queued so the admin request returns immediately, but a
 * large share of Magna installs never run `queue:work` (QUEUE_CONNECTION=database
 * with no supervised worker is the default state of a fresh install). On those
 * sites the job sat in the `jobs` table while the Plugins page showed "Queued…"
 * forever — the install looked broken when the queue was.
 *
 * So: the package is recorded before dispatch, and if nothing has picked the job
 * up after a short grace period, the polling request runs the install itself.
 * That is the same work a `sync` queue has always done inside the request, so it
 * introduces no capability the app didn't already have.
 *
 * Mirrors Magna\Updater\CoreUpdateStarter, which does this for core updates.
 */
final class PluginInstallStarter
{
    /**
     * How long a queued install may sit untouched before it is treated as
     * unqueueable. Long enough that a busy but working worker gets there first;
     * short enough that an admin isn't left watching a dead spinner.
     */
    public const WORKER_GRACE_SECONDS = 20;

    /**
     * Same shape PluginListing enforces on a catalog entry. The package name
     * reaches this class from the browser (the Plugins page keeps it in a plain
     * public property), and it ends up in a cache key and a queued job payload,
     * so it is checked here rather than trusted. install() re-verifies the
     * package against the catalog regardless — this is the cheap outer gate.
     */
    private const PACKAGE_PATTERN = '#^[a-z0-9]([a-z0-9_-]*[a-z0-9])?/[a-z0-9]([a-z0-9_-]*[a-z0-9])?$#';

    public function __construct(private readonly PluginInstaller $installer) {}

    public function start(string $package): bool
    {
        if (preg_match(self::PACKAGE_PATTERN, $package) !== 1) {
            return false;
        }

        InstallProgress::markQueued($package);

        InstallPluginJob::dispatch($package);

        return true;
    }

    /**
     * True when an install is still only queued after the grace period and this
     * install queued it itself — i.e. no worker is coming.
     */
    public function isStalled(string $package): bool
    {
        if (preg_match(self::PACKAGE_PATTERN, $package) !== 1) {
            return false;
        }

        $progress = InstallProgress::read($package);

        return $progress['state'] === InstallState::Queued->value
            && $progress['waiting_seconds'] >= self::WORKER_GRACE_SECONDS
            && InstallProgress::isPending($package);
    }

    /**
     * Runs the stalled install in the current request. Returns null when the
     * package was not (or no longer) claimable — including the case where a
     * concurrent poll took it first, which is what keeps a 2-second poll from
     * installing twice.
     */
    public function installStalledInline(string $package): ?InstallState
    {
        if (preg_match(self::PACKAGE_PATTERN, $package) !== 1) {
            return null;
        }

        if (! InstallProgress::claimPending($package)) {
            return null;
        }

        // Composer resolution plus enabling easily exceeds the default 30s; the
        // queued path had `$timeout = 900` for the same reason.
        if (function_exists('set_time_limit')) {
            set_time_limit(InstallPluginJob::TIMEOUT_SECONDS);
        }

        InstallProgress::set(
            $package,
            InstallState::Running,
            'No background worker found — installing in this browser session…',
        );

        $state = $this->installer->install($package);

        // The installer refuses to run two installs at once. Losing that race is
        // not a failure: put the package back in the queue so a later poll (or a
        // worker, if one appears) picks it up once the lock frees.
        if ($state === InstallState::Queued) {
            InstallProgress::markQueued($package, 'Waiting for another installation to finish…');
        }

        return $state;
    }
}
