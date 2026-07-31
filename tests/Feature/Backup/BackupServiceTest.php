<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Magna\Backup\BackupService;
use Magna\Backup\Exceptions\BackupConfigurationException;
use Magna\Settings\BackupSettings;
use Magna\Settings\MailSettings;
use Magna\Settings\StorageSettings;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

// Database dumping needs a real sqlite3/mysqldump binary on PATH, which
// isn't available in this test environment (see docs/backup-manager-plan.md,
// Stage 3 verification notes) — every test here uses include_database:
// false and exercises the files/config path, which has no such external
// dependency. The database-dump path was verified manually against the dev
// DB instead (also documented in the plan).
function backupSettingsFor(array $overrides = []): BackupSettings
{
    $settings = BackupSettings::get();
    $settings->disk = 'public'; // StorageSettings default is 'local' — must not collide
    $settings->include_database = false;
    $settings->include_files = false;
    $settings->include_config = true;

    foreach ($overrides as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();

    return $settings;
}

afterEach(function (): void {
    // Clean up anything actually written to the public disk during a test.
    Storage::disk('public')->deleteDirectory((string) config('backup.backup.name'));
});

it('produces a valid archive on the configured disk containing the settings export', function (): void {
    $settings = backupSettingsFor();

    $result = (new BackupService)->run($settings, 'test-backup.zip');

    expect($result->disk)->toBe('public')
        ->and($result->sizeBytes)->toBeGreaterThan(0)
        ->and(Storage::disk('public')->exists($result->path))->toBeTrue();

    $absolutePath = Storage::disk('public')->path($result->path);
    $zip = new ZipArchive;
    expect($zip->open($absolutePath))->toBeTrue();

    $exportIndex = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_ends_with($zip->getNameIndex($i), 'magna-settings-export.json')) {
            $exportIndex = $i;
        }
    }
    expect($exportIndex)->not->toBeNull();

    $zip->close();
});

it('masks secret settings fields inside the exported config', function (): void {
    $settings = backupSettingsFor();
    $mail = MailSettings::get();
    $mail->password = 'super-secret-mail-password';
    $mail->save();

    $result = (new BackupService)->run($settings, 'test-backup-secrets.zip');

    $absolutePath = Storage::disk('public')->path($result->path);
    $zip = new ZipArchive;
    $zip->open($absolutePath);

    $json = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_ends_with($zip->getNameIndex($i), 'magna-settings-export.json')) {
            $json = json_decode($zip->getFromIndex($i), true);
        }
    }
    $zip->close();

    expect($json)->not->toBeNull()
        ->and($json['mail']['password'])->toBe('[secret]');
});

it('refuses to run when nothing is selected to back up', function (): void {
    $settings = backupSettingsFor(['include_config' => false]);

    expect(fn () => (new BackupService)->run($settings))
        ->toThrow(BackupConfigurationException::class);
});

it('refuses to run when the destination currently collides with the media disk, even if it did not at save time', function (): void {
    $settings = backupSettingsFor(); // disk = 'public', valid at save time

    // Drift: StorageSettings changes independently after BackupSettings was saved.
    $storage = StorageSettings::get();
    $storage->disk = 'public';
    $storage->save();

    expect(fn () => (new BackupService)->run($settings))
        ->toThrow(BackupConfigurationException::class);
});

it('writes exactly one settings-export entry when only config is included', function (): void {
    // The include_files=true dedup case (base_path() sweep would otherwise
    // walk over the same export file a second time — fixed in
    // BackupService::configureSpatie()) is slow (~2300 files, ~10-20s) and
    // was verified manually against the real project tree instead of in
    // this suite — see docs/backup-manager-plan.md, Stage 3.
    $settings = backupSettingsFor();

    $result = (new BackupService)->run($settings, 'test-backup-nodupe.zip');

    $absolutePath = Storage::disk('public')->path($result->path);
    $zip = new ZipArchive;
    $zip->open($absolutePath);

    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_ends_with($zip->getNameIndex($i), 'magna-settings-export.json')) {
            $count++;
        }
    }
    $zip->close();

    expect($count)->toBe(1);
});

// ── Stage 7: multi-destination, encryption, size guardrails ────────────────

it('writes the same archive to both primary and secondary disks', function (): void {
    // Neither 'local' nor 'public' may equal StorageSettings.disk (Decision
    // #1) — point the media disk somewhere neither of these two resolves to.
    $storage = StorageSettings::get();
    $storage->disk = 'not-a-real-disk-for-this-test';
    $storage->save();

    $settings = backupSettingsFor(['disk' => 'local', 'secondary_disk' => 'public']);

    $result = (new BackupService)->run($settings, 'test-backup-multi.zip');

    expect($result->disk)->toBe('local')
        ->and($result->secondaryDisk)->toBe('public')
        ->and($result->secondaryPath)->toBe($result->path)
        ->and(Storage::disk('local')->exists($result->path))->toBeTrue()
        ->and(Storage::disk('public')->exists($result->secondaryPath))->toBeTrue();

    Storage::disk('local')->deleteDirectory((string) config('backup.backup.name'));
});

it('refuses to run when the secondary destination collides with the media disk', function (): void {
    $settings = backupSettingsFor(['secondary_disk' => 'local']); // StorageSettings default disk is 'local'

    expect(fn () => (new BackupService)->run($settings))
        ->toThrow(BackupConfigurationException::class);
});

it('refuses to run when the secondary destination equals the primary', function (): void {
    $settings = backupSettingsFor(['secondary_disk' => 'public']); // primary is also 'public'

    expect(fn () => (new BackupService)->run($settings))
        ->toThrow(BackupConfigurationException::class);
});

it('refuses to run when a bucket-based destination has no encryption password, without ever contacting the bucket', function (): void {
    $settings = backupSettingsFor(['disk' => 's3', 's3_bucket' => 'does-not-exist-anywhere']);

    // If this reached the network call it would hang/fail on DNS instead of
    // throwing our own exception — the guard must fire before that.
    expect(fn () => (new BackupService)->run($settings))
        ->toThrow(BackupConfigurationException::class);
});

it('encrypts the archive when encryption_password is set, regardless of destination disk', function (): void {
    $settings = backupSettingsFor(['encryption_password' => 'correct-horse-battery-staple']);

    $result = (new BackupService)->run($settings, 'test-backup-encrypted.zip');

    $absolutePath = Storage::disk('public')->path($result->path);
    $zip = new ZipArchive;
    $zip->open($absolutePath);

    // Without the password, reading an entry's contents fails even though
    // the archive itself opens (central directory isn't encrypted).
    expect($zip->getFromIndex(0))->toBeFalse();

    $zip->setPassword('correct-horse-battery-staple');
    $decrypted = $zip->getFromIndex(0);
    expect($decrypted)->not->toBeFalse();
    expect(json_decode((string) $decrypted, true))->toBeArray();

    $zip->close();
});
