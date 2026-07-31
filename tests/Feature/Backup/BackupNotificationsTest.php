<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Magna\Auth\Role;
use Magna\Backup\BackupRun;
use Magna\Backup\Jobs\RunBackupJob;
use Magna\Backup\Notifications\BackupFailedNotification;
use Magna\Backup\Notifications\BackupSizeWarningNotification;
use Magna\Backup\Notifications\BackupSucceededNotification;
use Magna\Settings\BackupSettings;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Cache::forget('magna.backup.run.lock');
    Cache::forget('magna.backup.run.progress');
    Notification::fake();
});

afterEach(function (): void {
    Storage::disk('public')->deleteDirectory((string) config('backup.backup.name'));
});

function notifiableSettings(array $overrides = []): BackupSettings
{
    $settings = BackupSettings::get();
    $settings->disk = 'public';
    $settings->include_database = false;
    $settings->include_files = false;
    $settings->include_config = true;
    $settings->notify_emails = [];

    foreach ($overrides as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();

    return $settings;
}

it('sends a success notification to notify_emails when configured', function (): void {
    notifiableSettings(['notify_emails' => ['ops@example.com']]);

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    Notification::assertSentOnDemand(BackupSucceededNotification::class);
});

it('sends no success notification when notify_emails is empty', function (): void {
    notifiableSettings();

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    Notification::assertSentOnDemandTimes(BackupSucceededNotification::class, 0);
});

it('sends a failure notification to notify_emails when configured', function (): void {
    notifiableSettings(['notify_emails' => ['ops@example.com'], 'include_config' => false]); // nothing selected -> fails

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    $run = BackupRun::query()->latest('created_at')->first();
    expect($run->status)->toBe(BackupRun::STATUS_FAILED);

    Notification::assertSentOnDemand(BackupFailedNotification::class);
});

it('falls back to notifying every super_admin when notify_emails is empty and a run fails', function (): void {
    notifiableSettings(['include_config' => false]); // nothing selected -> fails, notify_emails stays []

    $superAdminRole = Role::factory()->create(['is_super_admin' => true]);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole($superAdminRole);

    $regularRole = Role::factory()->create(['is_super_admin' => false]);
    $regularUser = User::factory()->create();
    $regularUser->assignRole($regularRole);

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    Notification::assertSentTo($superAdmin, BackupFailedNotification::class);
    Notification::assertNotSentTo($regularUser, BackupFailedNotification::class);
    Notification::assertSentOnDemandTimes(BackupFailedNotification::class, 0);
});

// ── Stage 7: size warning ────────────────────────────────────────────────────

it('sends a size warning when a successful backup exceeds the configured threshold', function (): void {
    $settings = notifiableSettings(['notify_emails' => ['ops@example.com']]);
    $settings->size_warning_mb = 0; // any real archive exceeds this
    $settings->save();

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    Notification::assertSentOnDemand(BackupSizeWarningNotification::class);
});

it('sends no size warning when notify_emails is empty, even over threshold', function (): void {
    $settings = notifiableSettings();
    $settings->size_warning_mb = 0;
    $settings->save();

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    Notification::assertSentOnDemandTimes(BackupSizeWarningNotification::class, 0);
});

it('sends no size warning when under threshold', function (): void {
    $settings = notifiableSettings(['notify_emails' => ['ops@example.com']]);
    $settings->size_warning_mb = 1_000_000; // far larger than a tiny test archive
    $settings->save();

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    Notification::assertSentOnDemandTimes(BackupSizeWarningNotification::class, 0);
});

// The panel-bell ("database" channel) regression tests live in
// BackupDashboardNotificationTest.php, deliberately NOT in this file —
// this file's global Notification::fake() also intercepts notifyNow()
// (Laravel's fake swaps the whole ChannelManager, sendNow included), so a
// real `notifications`-table assertion here would always read back empty
// regardless of whether the app code is correct. Same lesson as
// BackupNotificationRenderingTest.php: a blanket fake can hide exactly the
// bug it's supposed to help find.
