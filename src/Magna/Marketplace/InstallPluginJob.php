<?php

declare(strict_types=1);

namespace Magna\Marketplace;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued wrapper around {@see PluginInstaller} so an install runs in the
 * background and the admin request returns immediately.
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

    public function __construct(public readonly string $package) {}

    public function handle(PluginInstaller $installer): void
    {
        // If another install is in progress, wait our turn: put the job back on
        // the queue with a short delay (a simple serial install queue).
        if ($installer->install($this->package) === InstallState::Queued) {
            $this->release(10);
        }
    }
}
