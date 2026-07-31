<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Magna\Admin\Pages\BackupSettingsPage;
use Magna\Audit\AuditLog;
use Magna\Auth\Role;
use Magna\Settings\BackupSettings;
use Magna\Settings\StorageSettings;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();
});

function backupManagerSuperAdmin(): User
{
    $role = Role::factory()->create([
        'handle' => 'super_admin',
        'name' => 'Super Admin',
        'is_super_admin' => true,
    ]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

// ── Access control ──────────────────────────────────────────────────────────

it('is only accessible with the backup.manage permission', function (): void {
    $this->actingAs(User::factory()->create(['two_factor_confirmed_at' => now()]));

    expect(BackupSettingsPage::canAccess())->toBeFalse();
});

it('is accessible to a user with the backup.manage permission', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    expect(BackupSettingsPage::canAccess())->toBeTrue();
});

// ── Happy path ───────────────────────────────────────────────────────────────

it('saves backup settings when the destination differs from the media disk', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    // Default StorageSettings disk is 'local'; 'public' is a different
    // physical root (see config/filesystems.php), so this must not collide.
    Livewire::test(BackupSettingsPage::class)
        ->fillForm([
            'enabled' => true,
            'disk' => 'public',
            'frequency' => 'daily',
            'run_at' => '03:30',
            'retention_count' => 14,
            'retention_days' => 60,
            'include_database' => true,
            'include_files' => false,
            'include_config' => true,
            'notify_emails' => ['ops@example.com'],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $settings = BackupSettings::get();
    expect($settings->enabled)->toBeTrue()
        ->and($settings->disk)->toBe('public')
        ->and($settings->run_at)->toBe('03:30')
        ->and($settings->retention_count)->toBe(14)
        ->and($settings->retention_days)->toBe(60)
        ->and($settings->include_files)->toBeFalse()
        ->and($settings->notify_emails)->toBe(['ops@example.com']);
});

it('records a settings.changed audit entry for the backup group on save', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 'public'])
        ->call('save');

    $log = AuditLog::query()->where('action', 'settings.changed')->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->after['disk'] ?? null)->toBe('public');
});

// ── The Decision #1 guardrail: destination must not collide with media disk ──

it('rejects saving when the backup disk is identical to the storage media disk (local/local)', function (): void {
    $storage = StorageSettings::get();
    $storage->disk = 'local';
    $storage->save();

    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 'local'])
        ->call('save');

    // Not persisted: still the class default, never written to the settings table.
    expect(BackupSettings::get()->disk)->toBe('local')
        ->and(DB::table('settings')->where('group', 'backup')->where('key', 'disk')->exists())->toBeFalse();
});

it('rejects saving when both backup and storage resolve to the same S3 bucket, even across the s3/s3-like label', function (): void {
    $storage = StorageSettings::get();
    $storage->disk = 's3';
    $storage->s3_bucket = 'magna-prod';
    $storage->s3_region = 'us-east-1';
    $storage->s3_url = null;
    $storage->save();

    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm([
            'disk' => 's3-like',
            's3_bucket' => 'magna-prod',
            's3_region' => 'us-east-1',
            's3_url' => null,
        ])
        ->call('save');

    expect(DB::table('settings')->where('group', 'backup')->where('key', 's3_bucket')->exists())->toBeFalse();
});

it('allows saving when both are S3 but the bucket differs', function (): void {
    $storage = StorageSettings::get();
    $storage->disk = 's3';
    $storage->s3_bucket = 'magna-prod-media';
    $storage->s3_region = 'us-east-1';
    $storage->save();

    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm([
            'disk' => 's3',
            's3_bucket' => 'magna-prod-backups',
            's3_region' => 'us-east-1',
            'encryption_password' => 'correct-horse-battery-staple', // required for bucket-based destinations, Stage 7
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(BackupSettings::get()->s3_bucket)->toBe('magna-prod-backups');
});

// ── Secrets ──────────────────────────────────────────────────────────────────

it('encrypts the backup S3 secret at rest and round-trips it transparently', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 's3', 's3_bucket' => 'magna-backups', 's3_secret' => 'super-secret-key', 'encryption_password' => 'correct-horse-battery-staple'])
        ->call('save');

    $row = DB::table('settings')->where('group', 'backup')->where('key', 's3_secret')->first();
    expect($row)->not->toBeNull();
    $stored = json_decode((string) $row->value, true);
    expect($stored)->not->toBe('super-secret-key');

    expect(BackupSettings::get()->s3_secret)->toBe('super-secret-key');
});

it('does not overwrite the stored secret when the field is left blank', function (): void {
    $settings = BackupSettings::get();
    $settings->disk = 's3';
    $settings->s3_bucket = 'magna-backups';
    $settings->s3_secret = 'original-secret';
    $settings->encryption_password = 'correct-horse-battery-staple';
    $settings->save();

    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 's3', 's3_bucket' => 'magna-backups', 's3_secret' => null, 'encryption_password' => null])
        ->call('save')
        ->assertHasNoErrors();

    expect(BackupSettings::get()->s3_secret)->toBe('original-secret')
        ->and(BackupSettings::get()->encryption_password)->toBe('correct-horse-battery-staple');
});

// ── Stage 7: encryption requirement ─────────────────────────────────────────

it('rejects saving a bucket-based destination without an encryption password', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 's3', 's3_bucket' => 'magna-backups', 's3_region' => 'us-east-1'])
        ->call('save');

    expect(DB::table('settings')->where('group', 'backup')->where('key', 's3_bucket')->exists())->toBeFalse();
});

it('allows saving local/public destinations without an encryption password', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 'public'])
        ->call('save')
        ->assertHasNoErrors();

    expect(BackupSettings::get()->disk)->toBe('public');
});

// ── Stage 7: secondary destination ──────────────────────────────────────────

it('rejects saving when the secondary destination collides with the media disk', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    // StorageSettings default disk is 'local'.
    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 'public', 'secondary_disk' => 'local'])
        ->call('save');

    expect(DB::table('settings')->where('group', 'backup')->where('key', 'secondary_disk')->exists())->toBeFalse();
});

it('rejects saving when the secondary destination equals the primary', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 'public', 'secondary_disk' => 'public'])
        ->call('save');

    expect(DB::table('settings')->where('group', 'backup')->where('key', 'secondary_disk')->exists())->toBeFalse();
});

it('saves a genuinely independent secondary destination', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm([
            'disk' => 'public',
            'secondary_disk' => 's3',
            'secondary_s3_bucket' => 'magna-offsite',
            'secondary_s3_region' => 'us-east-1',
            'encryption_password' => 'correct-horse-battery-staple', // secondary is bucket-based, Stage 7 requires it
        ])
        ->call('save')
        ->assertHasNoErrors();

    $settings = BackupSettings::get();
    expect($settings->secondary_disk)->toBe('s3')
        ->and($settings->secondary_s3_bucket)->toBe('magna-offsite');
});

it('encrypts the secondary S3 secret at rest', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm([
            'disk' => 'public',
            'secondary_disk' => 's3',
            'secondary_s3_bucket' => 'magna-offsite',
            'secondary_s3_secret' => 'super-secret-offsite-key',
            'encryption_password' => 'correct-horse-battery-staple',
        ])
        ->call('save');

    $row = DB::table('settings')->where('group', 'backup')->where('key', 'secondary_s3_secret')->first();
    expect($row)->not->toBeNull();
    $stored = json_decode((string) $row->value, true);
    expect($stored)->not->toBe('super-secret-offsite-key');
    expect(BackupSettings::get()->secondary_s3_secret)->toBe('super-secret-offsite-key');
});

// ── Stage 7: size warning threshold ─────────────────────────────────────────

it('saves the size warning threshold, or clears it when left blank', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 'public', 'size_warning_mb' => 500])
        ->call('save')
        ->assertHasNoErrors();

    expect(BackupSettings::get()->size_warning_mb)->toBe(500);

    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 'public', 'size_warning_mb' => null])
        ->call('save');

    expect(BackupSettings::get()->size_warning_mb)->toBeNull();
});

// ── notify_emails must actually look like emails ────────────────────────────

it('rejects a malformed entry in notify_emails', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 'public', 'notify_emails' => ['not-an-email']])
        ->call('save')
        ->assertHasErrors(['data.notify_emails.0' => 'email']);

    expect(BackupSettings::get()->notify_emails)->toBe([]);
});

it('accepts a well-formed notify_emails list', function (): void {
    $this->actingAs(backupManagerSuperAdmin());

    Livewire::test(BackupSettingsPage::class)
        ->fillForm(['disk' => 'public', 'notify_emails' => ['ops@example.com', 'alerts@example.com']])
        ->call('save')
        ->assertHasNoErrors();

    expect(BackupSettings::get()->notify_emails)->toBe(['ops@example.com', 'alerts@example.com']);
});
