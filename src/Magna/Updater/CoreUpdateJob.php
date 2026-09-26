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
use ReflectionProperty;

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
        public readonly ?string $maintenanceSecret = null,
        public readonly string $mode = 'update',
    ) {}

    public static function fromPending(PendingCoreUpdate $pending): self
    {
        return new self(
            $pending->version,
            $pending->zipUrl,
            $pending->expectedSha256,
            $pending->force,
            $pending->checksumSignature,
            $pending->maintenanceSecret,
            $pending->mode,
        );
    }

    /**
     * `mode`, surviving a payload from before the property existed.
     *
     * A queued job is unserialized into whatever the class looks like when
     * the worker picks it up, and unserialization does not apply constructor
     * defaults: a property the payload lacks stays UNINITIALIZED. The job
     * that performs an update is queued by the OLD release and can be
     * retried by a worker already on the new one — the first live hop of a
     * 1.4.1 site did exactly that, and every retry died on
     * "Typed property CoreUpdateJob::$mode must not be accessed before
     * initialization" instead of noticing the update was already done.
     */
    private function modeValue(): string
    {
        // Reflection rather than isset(): the analyser proves the constructor
        // always initializes the property, and it is right about every path
        // except unserialize — the one this exists for.
        return (new ReflectionProperty($this, 'mode'))->isInitialized($this) ? $this->mode : UpdateMode::Update->value;
    }

    /** Same vintage, same reason as {@see modeValue()}. */
    private function maintenanceSecretValue(): ?string
    {
        return (new ReflectionProperty($this, 'maintenanceSecret'))->isInitialized($this) ? $this->maintenanceSecret : null;
    }

    public function handle(CoreUpdater $updater): void
    {
        $target = ltrim($this->targetVersion, 'vV');

        // A repair re-applies the running release and nothing else; an update
        // moves forward and nothing else. Skipped quietly, without a progress
        // entry: apply() has the same guard (UpdatePreflight) for every other
        // caller, but there it is a refusal the admin is shown.
        if ($this->modeValue() === UpdateMode::Repair->value) {
            if (version_compare(MagnaServiceProvider::VERSION, $target, '!=')) {
                Log::info('Skipping core repair job: it names v'.$target.' and this site runs v'.MagnaServiceProvider::VERSION.'.');

                return;
            }

            $state = $updater->repairFromHub($this->zipUrl, $this->expectedSha256, $this->checksumSignature, $this->maintenanceSecretValue());
        } else {
            // The install is already at (or past) this version, so someone else
            // applied it: either CoreUpdateStarter's stalled-update fallback ran it
            // in-request, or an earlier attempt of this same job succeeded. Running
            // again would re-download, re-overlay and take the site into maintenance
            // mode a second time for no gain.
            if (version_compare(MagnaServiceProvider::VERSION, $target, '>=')) {
                Log::info('Skipping core update job: already on v'.MagnaServiceProvider::VERSION.'.', [
                    'target' => $this->targetVersion,
                ]);

                return;
            }

            $state = $updater->apply(
                $this->targetVersion,
                $this->zipUrl,
                $this->expectedSha256,
                $this->force,
                $this->checksumSignature,
                $this->maintenanceSecretValue(),
            );
        }

        if ($state === CoreUpdateState::Queued) {
            $this->release(15);

            return;
        }

        // The switch is done and this worker is now the OLD code. The rest
        // belongs to a process on the new release: a fresh worker (this one
        // has been told to restart), the admin's poll, the scheduler's tick.
        if ($state === CoreUpdateState::Switched) {
            FinalizeCoreUpdateJob::dispatch()->delay(now()->addSeconds(5));
        }
    }
}
