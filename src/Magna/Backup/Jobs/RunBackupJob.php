<?php

declare(strict_types=1);

namespace Magna\Backup\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Magna\Admin\Notifications\NotificationRecipients;
use Magna\Backup\BackupRun;
use Magna\Backup\BackupRunOutcomeRecorder;
use Magna\Backup\BackupService;
use Magna\Backup\Concerns\TracksProgress;
use Magna\Backup\Notifications\BackupFailedNotification;
use Magna\Backup\Notifications\BackupSizeWarningNotification;
use Magna\Backup\Notifications\BackupSucceededNotification;
use Magna\Settings\BackupSettings;
use Throwable;

/**
 * Queued wrapper around {@see BackupService} — same shape as
 * Magna\Updater\CoreUpdateJob (lock + Cache-based progress an admin page
 * polls, mirroring CoreUpdater::progress()). Runs on the `database` queue so
 * a slow backup doesn't block the triggering request.
 */
class RunBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TracksProgress;

    private const LOCK_KEY = 'magna.backup.run.lock';

    /** Generous ceiling — a large database/media backup can be slow. */
    public int $timeout = 1800;

    public function __construct(
        public readonly string $type,
        public readonly ?string $triggeredBy = null,
    ) {}

    public function handle(BackupService $service, BackupRunOutcomeRecorder $outcomes): void
    {
        $lock = Cache::lock(self::LOCK_KEY, 1800);

        if (! $lock->get()) {
            // Reject rather than auto-retry: two overlapping runs writing to
            // the same destination is worse than one being skipped, and a
            // scheduled run (Stage 5) will simply try again next tick.
            $this->setProgress('rejected', 'Another backup is already running — this run was skipped.');

            return;
        }

        // Outer try/finally guarantees the lock is released even if the setup
        // steps below (BackupRun::create, setProgress, settings load) throw —
        // previously only the $service->run() call was inside a finally, so a
        // failure before it leaked the lock for its full 1800s TTL.
        try {
            $run = BackupRun::create([
                'type' => $this->type,
                'status' => BackupRun::STATUS_RUNNING,
                'triggered_by' => $this->triggeredBy,
                'started_at' => now(),
            ]);

            $this->setProgress('running', 'Backing up…');
            $settings = BackupSettings::get();

            try {
                $result = $service->run($settings);
                $outcomes->succeeded($run, $result, $this->triggeredBy);

                $this->setProgress('completed', 'Backup completed.');
                $this->notifySuccess($settings, $run);
                $this->notifySizeWarning($settings, $run);
                NotificationRecipients::notifyDashboard(
                    title: 'Backup completed',
                    body: ucfirst($run->type)." backup completed successfully ({$this->humanBytes($result->sizeBytes)}).",
                    status: 'success',
                );
            } catch (Throwable $e) {
                $outcomes->failed($run, $e, $this->triggeredBy);

                $this->setProgress('failed', $e->getMessage());
                $this->notifyFailure($settings, $run);
                NotificationRecipients::notifyDashboard(
                    title: "Backup didn't complete",
                    body: ucfirst($run->type)." backup failed: {$e->getMessage()}",
                    status: 'danger',
                );
            }
        } finally {
            $lock->release();
        }
    }

    /** Success alerts are opt-in — only sent when notify_emails is non-empty. */
    private function notifySuccess(BackupSettings $settings, BackupRun $run): void
    {
        if ($settings->notify_emails === []) {
            return;
        }

        NotificationFacade::route('mail', $settings->notify_emails)->notify(new BackupSucceededNotification($run));
    }

    /**
     * Alert-only, gated the same as success (opt-in via notify_emails) —
     * an oversized backup isn't a failure, so it doesn't get the
     * always-fires/super_admin-fallback treatment notifyFailure() does.
     */
    private function notifySizeWarning(BackupSettings $settings, BackupRun $run): void
    {
        if ($settings->size_warning_mb === null || $settings->notify_emails === []) {
            return;
        }

        $sizeMb = ($run->size_bytes ?? 0) / 1_048_576;
        if ($sizeMb <= $settings->size_warning_mb) {
            return;
        }

        NotificationFacade::route('mail', $settings->notify_emails)
            ->notify(new BackupSizeWarningNotification($run, $settings->size_warning_mb));
    }

    /**
     * Failure alerts are never optional. If no explicit notify_emails is
     * configured, every super_admin gets it instead — a backup system must
     * not be able to fail silently just because nobody filled in the
     * settings field yet.
     */
    private function notifyFailure(BackupSettings $settings, BackupRun $run): void
    {
        if ($settings->notify_emails !== []) {
            NotificationFacade::route('mail', $settings->notify_emails)->notify(new BackupFailedNotification($run));

            return;
        }

        NotificationFacade::send(NotificationRecipients::superAdmins(), new BackupFailedNotification($run));
    }

    private function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => number_format($bytes / 1_073_741_824, 2).' GB',
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 1).' MB',
            $bytes >= 1_024 => number_format($bytes / 1_024, 0).' KB',
            default => number_format($bytes).' B',
        };
    }

    protected static function progressKey(): string
    {
        return 'magna.backup.run.progress';
    }
}
