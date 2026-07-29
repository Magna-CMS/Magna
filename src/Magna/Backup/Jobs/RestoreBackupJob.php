<?php

declare(strict_types=1);

namespace Magna\Backup\Jobs;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Magna\Audit\AuditLog;
use Magna\Backup\BackupRun;
use Magna\Backup\Concerns\TracksProgress;
use Magna\Backup\RestoreService;
use Magna\Users\User;
use Throwable;

/**
 * Guided restore — deliberately hard-gated (super_admin only, type-to-confirm
 * modal, see BackupResource) and deliberately narrow in what it automates.
 * See RestoreService's docblock for the exact scope limits and why a failed
 * database restore is never auto-reversed.
 *
 * Two sources, same job: `backupRunId` restores one of this Magna
 * instance's own recorded runs; `importDisk`/`importPath` (set by
 * BackupSettingsPage's "Import" action) restores an admin-uploaded archive
 * that isn't tied to a `backup_runs` row at all — e.g. brought in from
 * another environment. Exactly one of the two must be set.
 *
 * The instance goes into maintenance mode for the duration, same shape as
 * Magna\Updater\CoreUpdater's apply(). Unlike CoreUpdater, this does NOT
 * unconditionally bring the instance back up on failure: once the database
 * restore has started, a failure leaves maintenance mode ON rather than
 * risk serving traffic against a database in an unknown state. A failure
 * before that point (extraction, archive not found, etc.) never touched
 * anything, so it's safe to reopen immediately.
 */
class RestoreBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TracksProgress;

    private const LOCK_KEY = 'magna.backup.restore.lock';

    public int $timeout = 1800;

    public function __construct(
        public readonly ?string $backupRunId,
        public readonly bool $useSecondary,
        public readonly ?string $triggeredBy,
        public readonly ?string $importDisk = null,
        public readonly ?string $importPath = null,
        public readonly ?string $importPasswordToken = null,
    ) {}

    /**
     * An uploaded archive's password must never sit in the `jobs` table as a
     * plain constructor property — with a real queue driver (`database`,
     * the default in this app), a dispatched job can wait there for however
     * long it takes a worker to pick it up, which found live in this same
     * session could be a long time with no worker running at all. Encrypted
     * and cached under a short TTL instead; resolveImportPassword() pulls
     * (read-once) and decrypts it at the moment it's actually needed, not
     * before.
     */
    public static function stashImportPassword(string $password): string
    {
        $token = Str::random(40);
        Cache::put(self::importPasswordCacheKey($token), Crypt::encryptString($password), now()->addMinutes(30));

        return $token;
    }

    private static function resolveImportPassword(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }

        $encrypted = Cache::pull(self::importPasswordCacheKey($token));

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    private static function importPasswordCacheKey(string $token): string
    {
        return "magna.backup.import-password.{$token}";
    }

    public function handle(RestoreService $service): void
    {
        $lock = Cache::lock(self::LOCK_KEY, 1800);

        if (! $lock->get()) {
            $this->setProgress('rejected', 'Another restore is already in progress — this one was skipped.');

            return;
        }

        // Outer try/finally guarantees the lock is released on every exit path
        // below — the not-found early return, the setup steps (BackupRun::find,
        // setProgress) that run before the main try, and the main restore.
        try {
            $run = $this->backupRunId !== null ? BackupRun::find($this->backupRunId) : null;

            if ($this->backupRunId !== null && $run === null) {
                $this->setProgress('failed', 'The selected backup run no longer exists.');

                return;
            }

            $databaseTouched = false;
            $auditAfter = $run !== null ? ['used_secondary' => $this->useSecondary] : ['imported' => true, 'import_path' => $this->importPath];

            try {
                $this->setProgress('running', 'Extracting archive…');

                if ($run !== null) {
                    $service->prepare($run, $this->useSecondary);
                } else {
                    $service->prepareFromDiskPath(
                        (string) $this->importDisk,
                        (string) $this->importPath,
                        self::resolveImportPassword($this->importPasswordToken),
                    );
                }

                $this->setProgress('running', 'Entering maintenance mode…');
                Artisan::call('down', ['--retry' => 60]);

                $this->setProgress('running', 'Restoring database…');
                $databaseTouched = true;
                $service->restoreDatabase();

                $this->setProgress('running', 'Restoring files…');
                $service->restoreFiles();

                Artisan::call('up');

                $this->setProgress('completed', 'Restore completed. The instance is back online.');

                AuditLog::record(
                    action: 'backup.restored',
                    actorId: $this->triggeredBy,
                    actorType: $this->triggeredBy !== null ? 'user' : 'system',
                    subject: $run,
                    after: $auditAfter,
                );

                $this->notifyTrigger('Restore completed', 'The instance is back online.', success: true);
            } catch (Throwable $e) {
                if ($databaseTouched) {
                    $message = 'Restore failed after the database was touched — the instance is deliberately left in maintenance mode until this is manually resolved. '.$e->getMessage();
                    $this->setProgress('failed', $message);
                } else {
                    Artisan::call('up');
                    $message = 'Restore failed before anything was changed — the instance was never touched. '.$e->getMessage();
                    $this->setProgress('failed', $message);
                }

                AuditLog::record(
                    action: 'backup.restore_failed',
                    actorId: $this->triggeredBy,
                    actorType: $this->triggeredBy !== null ? 'user' : 'system',
                    subject: $run,
                    after: [...$auditAfter, 'error' => $e->getMessage(), 'database_touched' => $databaseTouched],
                );

                $this->notifyTrigger("Restore didn't complete", $message, success: false);
            } finally {
                $service->cleanup();

                // The uploaded source archive itself (distinct from the
                // service's own temp extraction, already cleaned above) —
                // never leave an uploaded DB dump sitting on disk longer than
                // the restore attempt that used it.
                if ($this->importDisk !== null && $this->importPath !== null) {
                    try {
                        Storage::disk($this->importDisk)->delete($this->importPath);
                    } catch (Throwable) {
                    }
                }
            }
        } finally {
            $lock->release();
        }
    }

    private function notifyTrigger(string $title, string $body, bool $success): void
    {
        if ($this->triggeredBy === null) {
            return;
        }

        $user = User::find($this->triggeredBy);
        if ($user === null) {
            return;
        }

        $notification = FilamentNotification::make()->title($title)->body($body);
        $success ? $notification->success() : $notification->danger();

        // NOT ->sendToDatabase(): Filament\Notifications\DatabaseNotification
        // implements ShouldQueue, so that call only enqueues a job rather than
        // writing the row — found live while fixing the identical gap in
        // RunBackupJob. notifyNow() bypasses the queue and sends immediately.
        $user->notifyNow($notification->toDatabase());
    }

    protected static function progressKey(): string
    {
        return 'magna.backup.restore.progress';
    }
}
