<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Magna\Admin\Pages\BackupSettingsPage;
use Magna\Auth\Role;
use Magna\Backup\Jobs\RestoreBackupJob;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Import is at least as dangerous as restoring an existing run (Stage 8) —
// same policy applies: never let RestoreBackupJob actually execute in this
// suite (it calls real Artisan down/up). Queue::fake() intercepts dispatch
// before handle() ever runs, same technique used for other queued jobs
// elsewhere in this codebase (see PluginsPageMarketplaceTest.php).

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();
});

function importSuperAdmin(): User
{
    $role = Role::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('does not offer the import action to a non-super-admin', function (): void {
    $role = Role::factory()->create();
    $role->grant('backup.manage'); // enough to reach the settings page, not enough for import
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);
    $this->actingAs($user);

    Livewire::test(BackupSettingsPage::class)
        ->assertOk()
        ->assertDontSee('Import backup');
});

it('offers the import action to a super_admin', function (): void {
    $this->actingAs(importSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->assertActionVisible('importBackup');
});

it('rejects the import when the confirmation text is wrong, without dispatching anything', function (): void {
    Queue::fake();
    $this->actingAs(importSuperAdmin());

    $file = UploadedFile::fake()->create('backup.zip', 100);

    Livewire::test(BackupSettingsPage::class)
        ->callAction('importBackup', data: [
            'archive' => $file,
            'confirm' => 'not restore',
        ])
        ->assertHasActionErrors(['confirm']);

    Queue::assertNotPushed(RestoreBackupJob::class);
});

it('dispatches RestoreBackupJob with the uploaded file and no backupRunId when confirmed correctly', function (): void {
    Queue::fake();
    $admin = importSuperAdmin();
    $this->actingAs($admin);

    $file = UploadedFile::fake()->create('backup.zip', 100);

    Livewire::test(BackupSettingsPage::class)
        ->callAction('importBackup', data: [
            'archive' => $file,
            'confirm' => 'RESTORE',
        ])
        ->assertHasNoActionErrors();

    Queue::assertPushed(RestoreBackupJob::class, function (RestoreBackupJob $job) use ($admin): bool {
        return $job->backupRunId === null
            && $job->importDisk === 'local'
            && $job->importPath !== null
            && $job->triggeredBy === (string) $admin->id;
    });
});

// ── Security: archive password never reaches the job as plaintext ──────────

it('passes an archive password as an opaque token, never as plaintext, on the dispatched job', function (): void {
    Queue::fake();
    $this->actingAs(importSuperAdmin());

    $file = UploadedFile::fake()->create('backup.zip', 100);

    Livewire::test(BackupSettingsPage::class)
        ->callAction('importBackup', data: [
            'archive' => $file,
            'archive_password' => 'super-secret-archive-password',
            'confirm' => 'RESTORE',
        ])
        ->assertHasNoActionErrors();

    Queue::assertPushed(RestoreBackupJob::class, function (RestoreBackupJob $job): bool {
        return $job->importPasswordToken !== null
            && $job->importPasswordToken !== 'super-secret-archive-password';
    });
});

it('leaves the password null on the job when no archive password is supplied', function (): void {
    Queue::fake();
    $this->actingAs(importSuperAdmin());

    $file = UploadedFile::fake()->create('backup.zip', 100);

    Livewire::test(BackupSettingsPage::class)
        ->callAction('importBackup', data: [
            'archive' => $file,
            'confirm' => 'RESTORE',
        ])
        ->assertHasNoActionErrors();

    Queue::assertPushed(RestoreBackupJob::class, fn (RestoreBackupJob $job): bool => $job->importPasswordToken === null);
});
