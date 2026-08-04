<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Magna\MagnaServiceProvider;

/**
 * Queued wrapper around {@see CoreUpdater} so applying an update runs in the
 * background and the admin request returns immediately — same shape as
 * Magna\Marketplace\InstallPluginJob.
 */
class CoreUpdateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Generous ceiling — download + overlay + migrate can be slow. */
    public const TIMEOUT_SECONDS = 1800;

    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(
        public readonly string $targetVersion,
        public readonly string $zipUrl,
        public readonly ?string $expectedSha256 = null,
        public readonly bool $force = false,
        public readonly ?string $checksumSignature = null,
    ) {}

    public function handle(CoreUpdater $updater): void
    {
        // The install is already at (or past) this version, so someone else
        // applied it: either CoreUpdateStarter's stalled-update fallback ran it
        // in-request, or an earlier attempt of this same job succeeded. Running
        // again would re-download, re-overlay and take the site into maintenance
        // mode a second time for no gain.
        if (version_compare(MagnaServiceProvider::VERSION, $this->targetVersion, '>=')) {
            Log::info('Skipping core update job: already on v'.MagnaServiceProvider::VERSION.'.', [
                'target' => $this->targetVersion,
            ]);

            return;
        }

        if ($updater->apply($this->targetVersion, $this->zipUrl, $this->expectedSha256, $this->force, $this->checksumSignature) === CoreUpdateState::Queued) {
            $this->release(15);
        }
    }
}
