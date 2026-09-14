<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Magna\Backup\BackupPlanPersister;
use Magna\Settings\BackupSettings;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The destination/encryption refusals are exercised end-to-end through the
// page in BackupSettingsPageTest; these unit tests pin the persister's own
// contract — above all the Stage 8 cron gate, which previously lived inline
// in a Livewire method and had no direct coverage at all.

function persistBackupPlan(array $overrides = []): array
{
    return app(BackupPlanPersister::class)->persist($overrides + [
        'enabled' => true,
        'disk' => 'public', // never collides with the default 'local' media disk
        'frequency' => 'daily',
        'run_at' => '02:00',
        'retention_count' => 7,
        'retention_days' => 30,
    ]);
}

it('persists a valid plan and reports success', function (): void {
    $result = persistBackupPlan(['run_at' => '03:15', 'retention_count' => 12]);

    expect($result['ok'])->toBeTrue()
        ->and($result['title'])->toBe('Backup settings saved.')
        ->and(BackupSettings::get()->run_at)->toBe('03:15')
        ->and(BackupSettings::get()->retention_count)->toBe(12);
});

it('rejects a custom_cron plan with no cron expression, and persists nothing', function (): void {
    $result = persistBackupPlan(['frequency' => 'custom_cron', 'cron_expression' => null]);

    expect($result['ok'])->toBeFalse()
        ->and($result['title'])->toBe('Backup settings not saved')
        ->and($result['message'])->toContain('cron expression is required')
        ->and(DB::table('settings')->where('group', 'backup')->exists())->toBeFalse();
});

it('rejects an invalid cron expression, and persists nothing', function (): void {
    $result = persistBackupPlan(['frequency' => 'custom_cron', 'cron_expression' => 'not a cron']);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toBe("'not a cron' is not a valid cron expression.")
        ->and(DB::table('settings')->where('group', 'backup')->exists())->toBeFalse();
});

it('accepts a valid custom cron expression and persists it', function (): void {
    $result = persistBackupPlan(['frequency' => 'custom_cron', 'cron_expression' => '15 3 * * 1']);

    expect($result['ok'])->toBeTrue()
        ->and(BackupSettings::get()->frequency)->toBe('custom_cron')
        ->and(BackupSettings::get()->cron_expression)->toBe('15 3 * * 1');
});

it('rejects a destination on the media disk with the destination-specific title', function (): void {
    // StorageSettings default disk is 'local'.
    $result = persistBackupPlan(['disk' => 'local']);

    expect($result['ok'])->toBeFalse()
        ->and($result['title'])->toBe('Backup destination not saved')
        ->and(DB::table('settings')->where('group', 'backup')->exists())->toBeFalse();
});

it('keeps stored secrets when the corresponding fields come in blank', function (): void {
    $settings = BackupSettings::get();
    $settings->disk = 'public';
    $settings->s3_secret = 'original-secret';
    $settings->encryption_password = 'original-password';
    $settings->save();

    $result = persistBackupPlan(['s3_secret' => null, 'encryption_password' => null]);

    expect($result['ok'])->toBeTrue()
        ->and(BackupSettings::get()->s3_secret)->toBe('original-secret')
        ->and(BackupSettings::get()->encryption_password)->toBe('original-password');
});
