<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Magna\Admin\Resources\BackupResource;
use Magna\Auth\Role;
use Magna\Backup\BackupRun;
use Magna\Backup\Jobs\RunBackupJob;
use Magna\Settings\BackupSettings;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();
    Cache::forget('magna.backup.run.lock');
    Cache::forget('magna.backup.run.progress');
});

afterEach(function (): void {
    Storage::disk('public')->deleteDirectory((string) config('backup.backup.name'));
});

it('denies viewing backup history with zero backup permissions', function (): void {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->actingAs($user);

    expect(BackupResource::canViewAny())->toBeFalse();
});

it('grants viewing backup history with backup.view', function (): void {
    $role = Role::factory()->create();
    $role->grant('backup.view');

    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);
    $this->actingAs($user);

    expect(BackupResource::canViewAny())->toBeTrue();
});

it('super admin has full access without explicit grants', function (): void {
    $role = Role::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);
    $this->actingAs($user);

    expect(BackupResource::canViewAny())->toBeTrue();
});

it('never allows creating, editing, or deleting backup run records through the resource', function (): void {
    $role = Role::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);
    $this->actingAs($user);

    $run = BackupRun::create(['type' => BackupRun::TYPE_MANUAL, 'status' => BackupRun::STATUS_SUCCESS]);

    expect(BackupResource::canCreate())->toBeFalse()
        ->and(BackupResource::canEdit($run))->toBeFalse()
        ->and(BackupResource::canDelete($run))->toBeFalse()
        ->and(BackupResource::canDeleteAny())->toBeFalse();
});

// ── Download route ────────────────────────────────────────────────────────────

it('downloads a completed backup when the user holds backup.manage', function (): void {
    $settings = BackupSettings::get();
    $settings->disk = 'public';
    $settings->include_database = false;
    $settings->include_files = false;
    $settings->include_config = true;
    $settings->save();

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);
    $run = BackupRun::query()->latest('created_at')->first();
    expect($run->status)->toBe(BackupRun::STATUS_SUCCESS);

    $role = Role::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);
    $this->actingAs($user);

    $response = $this->get(route('magna.backup.download', $run));

    $response->assertOk();
    $response->assertHeader('content-disposition');
});

it('denies downloading without backup.manage', function (): void {
    $run = BackupRun::create(['type' => BackupRun::TYPE_MANUAL, 'status' => BackupRun::STATUS_SUCCESS, 'disk' => 'public', 'path' => 'Magna CMS/does-not-matter.zip']);

    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->actingAs($user);

    $response = $this->get(route('magna.backup.download', $run));

    $response->assertForbidden();
});

it('404s for a run that has no successful archive', function (): void {
    $run = BackupRun::create(['type' => BackupRun::TYPE_MANUAL, 'status' => BackupRun::STATUS_FAILED]);

    $role = Role::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);
    $this->actingAs($user);

    $response = $this->get(route('magna.backup.download', $run));

    $response->assertNotFound();
});
