<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Magna\Auth\Role;
use Magna\Backup\BackupRun;
use Magna\Backup\Jobs\RunBackupJob;
use Magna\Settings\BackupSettings;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Deliberately no Notification::fake() — see BackupNotificationsTest.php for
// why. Two real bugs were found writing these tests, not by inspection:
//
// 1. Every backup notification only declared the `mail` channel — an admin
//    checked the panel bell after a real run and found nothing there at all,
//    regardless of notify_emails. Fixed by always sending a `database`-
//    channel notification to every super_admin, independent of the mail
//    opt-in logic (RunBackupJob::notifyDashboard()).
// 2. That fix *also* silently did nothing at first: Filament's own
//    Notification::sendToDatabase() calls $user->notify(...), and
//    Filament\Notifications\DatabaseNotification implements ShouldQueue —
//    so it only enqueues a job rather than writing the row. Confirmed via a
//    real dispatch outside this suite: the row appeared only after manually
//    running the queue worker. Fixed with notifyNow(), which bypasses the
//    queue — same latent bug existed in RestoreBackupJob::notifyTrigger()
//    (fixed identically) even though it predates this session's change.

beforeEach(function (): void {
    Cache::flush();
    Cache::forget('magna.backup.run.lock');
    Cache::forget('magna.backup.run.progress');
});

afterEach(function (): void {
    Storage::disk('public')->deleteDirectory((string) config('backup.backup.name'));
});

function dashboardNotificationSettings(array $overrides = []): BackupSettings
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

it('posts a bell notification to every super_admin on success, independent of notify_emails', function (): void {
    dashboardNotificationSettings(); // notify_emails empty — mail opt-out, bell must still fire

    $superAdminRole = Role::factory()->create(['is_super_admin' => true]);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole($superAdminRole);

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    $row = DB::table('notifications')->where('notifiable_id', $superAdmin->id)->first();

    expect($row)->not->toBeNull();
    $data = json_decode((string) $row->data, true);
    expect($data['title'])->toBe('Backup completed')
        ->and($data['format'])->toBe('filament'); // required for Filament's bell to render it
});

it('posts a bell notification to every super_admin on failure, independent of notify_emails', function (): void {
    dashboardNotificationSettings(['include_config' => false]); // nothing selected -> fails

    $superAdminRole = Role::factory()->create(['is_super_admin' => true]);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole($superAdminRole);

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    $row = DB::table('notifications')->where('notifiable_id', $superAdmin->id)->first();

    expect($row)->not->toBeNull();
    $data = json_decode((string) $row->data, true);
    expect($data['title'])->toBe("Backup didn't complete")
        ->and($data['status'])->toBe('danger');
});

it('does not post a bell notification to a non-super-admin', function (): void {
    dashboardNotificationSettings();

    $regularRole = Role::factory()->create(['is_super_admin' => false]);
    $regularUser = User::factory()->create();
    $regularUser->assignRole($regularRole);

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    expect(DB::table('notifications')->where('notifiable_id', $regularUser->id)->exists())->toBeFalse();
});

it('writes the row synchronously, not just onto the queue', function (): void {
    // Regression for bug #2 above: sendToDatabase() alone would leave this
    // at 0 until a worker ran. notifyNow() must make it appear immediately,
    // in the same request/process, with nothing left pending on the queue.
    dashboardNotificationSettings();

    $superAdminRole = Role::factory()->create(['is_super_admin' => true]);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole($superAdminRole);

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    expect(DB::table('notifications')->where('notifiable_id', $superAdmin->id)->count())->toBe(1)
        ->and(DB::table('jobs')->where('payload', 'like', '%DatabaseNotification%')->exists())->toBeFalse();
});
