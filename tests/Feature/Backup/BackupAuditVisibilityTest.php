<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Magna\Admin\Resources\AuditLog\ListAuditLogs;
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

it('shows a completed backup run in the Audit Log admin UI, not just the table', function (): void {
    $settings = BackupSettings::get();
    $settings->disk = 'public';
    $settings->include_database = false;
    $settings->include_files = false;
    $settings->include_config = true;
    $settings->save();

    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, null);

    $role = Role::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);
    $this->actingAs($user);

    Livewire::test(ListAuditLogs::class)
        ->assertOk()
        ->assertSee('Backup Completed');
});
