<?php

declare(strict_types=1);

namespace Magna\Marketplace;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Magna\Admin\Notifications\NotificationRecipients;
use Throwable;

/**
 * Queued wrapper around {@see PluginInstaller} so an install — or an update,
 * which is the same Composer run against a newer pinned version — happens in
 * the background and the admin request returns immediately.
 */
class InstallPluginJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A generous ceiling — Composer installs can be slow. */
    public const TIMEOUT_SECONDS = 900;

    public int $timeout = self::TIMEOUT_SECONDS;

    /**
     * Whether this is an update. A declared default, deliberately not a
     * promoted constructor default: a queued job outlives the release that
     * queued it, and unserialization applies a property's declared default
     * but never a constructor's. Jobs queued before this flag existed died
     * with "$update must not be accessed before initialization" and were
     * never retried.
     */
    public bool $update = false;

    public function __construct(public readonly string $package, bool $update = false)
    {
        $this->update = $update;
    }

    public function handle(PluginInstaller $installer): void
    {
        // If another install is in progress, wait our turn: put the job back on
        // the queue with a short delay (a simple serial install queue).
        $state = $this->update
            ? $installer->update($this->package)
            : $installer->install($this->package);

        if ($state === InstallState::Queued) {
            $this->release(10);
        }
    }

    /**
     * A job that dies outside the installer — a worker killed mid-run, a
     * payload that cannot be read — left its progress at "Queued" forever,
     * and the admin who clicked saw nothing happen. Say so, here and in the
     * bell.
     */
    public function failed(Throwable $e): void
    {
        $verb = $this->update ? 'update' : 'install';

        InstallProgress::clearPending($this->package);
        InstallProgress::set($this->package, InstallState::Failed, "The {$verb} stopped: ".$e->getMessage());

        NotificationRecipients::notifyDashboard(
            "Couldn't {$verb} {$this->package}",
            $e->getMessage(),
            'danger',
        );
    }
}
