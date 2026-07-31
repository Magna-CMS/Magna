<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Magna\Audit\AuditLog;
use Magna\Backup\BackupRun;
use Magna\Backup\Jobs\RunBackupJob;
use Magna\Settings\BackupSettings;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Cache::forget('magna.backup.run.lock');
    Cache::forget('magna.backup.run.progress');

    $settings = BackupSettings::get();
    $settings->disk = 'public'; // StorageSettings default is 'local' — must not collide
    $settings->include_database = false; // no sqlite3/mysqldump dependency in the automated suite
    $settings->include_files = false;
    $settings->include_config = true;
    $settings->save();
});

afterEach(function (): void {
    Storage::disk('public')->deleteDirectory((string) config('backup.backup.name'));
    Cache::forget('magna.backup.run.lock');
    Cache::forget('magna.backup.run.progress');
});

it('runs a manual backup, records a success run, and audits it', function (): void {
    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, 'user-123');

    $run = BackupRun::query()->latest('created_at')->first();

    expect($run)->not->toBeNull()
        ->and($run->type)->toBe(BackupRun::TYPE_MANUAL)
        ->and($run->status)->toBe(BackupRun::STATUS_SUCCESS)
        ->and($run->triggered_by)->toBe('user-123')
        ->and($run->disk)->toBe('public')
        ->and($run->size_bytes)->toBeGreaterThan(0)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();

    expect(RunBackupJob::progress())->toBe(['state' => 'completed', 'message' => 'Backup completed.']);

    $log = AuditLog::query()->where('action', 'backup.completed')->latest('created_at')->first();
    expect($log)->not->toBeNull()
        ->and($log->subject_id)->toBe($run->id)
        ->and($log->actor_id)->toBe('user-123');
});

it('records a failed run and audits it when the backup engine throws', function (): void {
    // Nothing selected to back up — BackupService::run() throws BackupConfigurationException.
    $settings = BackupSettings::get();
    $settings->include_config = false;
    $settings->save();

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, 'user-123');

    $run = BackupRun::query()->latest('created_at')->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(BackupRun::STATUS_FAILED)
        ->and($run->error_message)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();

    expect(RunBackupJob::progress()['state'])->toBe('failed');

    $log = AuditLog::query()->where('action', 'backup.failed')->latest('created_at')->first();
    expect($log)->not->toBeNull()
        ->and($log->subject_id)->toBe($run->id);
});

it('rejects a run instead of running concurrently when the lock is already held', function (): void {
    $lock = Cache::lock('magna.backup.run.lock', 1800);
    expect($lock->get())->toBeTrue(); // simulate another run already in progress

    try {
        RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, 'user-123');

        expect(BackupRun::query()->count())->toBe(0)
            ->and(RunBackupJob::progress()['state'])->toBe('rejected');
    } finally {
        $lock->release();
    }
});

it('records type=scheduled and a null triggered_by for a scheduled run', function (): void {
    RunBackupJob::dispatch(BackupRun::TYPE_SCHEDULED, null);

    $run = BackupRun::query()->latest('created_at')->first();

    expect($run->type)->toBe(BackupRun::TYPE_SCHEDULED)
        ->and($run->triggered_by)->toBeNull();

    $log = AuditLog::query()->where('action', 'backup.completed')->latest('created_at')->first();
    expect($log->actor_type)->toBe('system');
});
