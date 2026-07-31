<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Magna\Settings\BackupSettings;
use Magna\Settings\StorageSettings;

beforeEach(function (): void {
    Cache::flush();
});

it('does not collide when disks are different local roots', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 'public';

    $storage = new StorageSettings;
    $storage->disk = 'local';

    expect($backup->collidesWithMediaDisk($storage))->toBeFalse();
});

it('collides when both are the local disk', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 'local';

    $storage = new StorageSettings;
    $storage->disk = 'local';

    expect($backup->collidesWithMediaDisk($storage))->toBeTrue();
});

it('collides on identical S3 bucket/region/url regardless of the s3 vs s3-like label', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 's3-like';
    $backup->s3_bucket = 'shared-bucket';
    $backup->s3_region = 'eu-west-1';
    $backup->s3_url = 'https://example.r2.cloudflarestorage.com';

    $storage = new StorageSettings;
    $storage->disk = 's3';
    $storage->s3_bucket = 'shared-bucket';
    $storage->s3_region = 'eu-west-1';
    $storage->s3_url = 'https://example.r2.cloudflarestorage.com';

    expect($backup->collidesWithMediaDisk($storage))->toBeTrue();
});

it('does not collide on S3 when the bucket differs', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 's3';
    $backup->s3_bucket = 'backups-bucket';
    $backup->s3_region = 'us-east-1';

    $storage = new StorageSettings;
    $storage->disk = 's3';
    $storage->s3_bucket = 'media-bucket';
    $storage->s3_region = 'us-east-1';

    expect($backup->collidesWithMediaDisk($storage))->toBeFalse();
});

it('does not collide when disks are unrelated families (local vs s3)', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 's3';
    $backup->s3_bucket = 'backups-bucket';

    $storage = new StorageSettings;
    $storage->disk = 'local';

    expect($backup->collidesWithMediaDisk($storage))->toBeFalse();
});

// ── Stage 7: secondary destination ──────────────────────────────────────────

it('secondary does not collide with media disk when unset', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 'public';

    $storage = new StorageSettings;
    $storage->disk = 'local';

    expect($backup->secondaryCollidesWithMediaDisk($storage))->toBeFalse();
});

it('secondary collides with media disk when it matches', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 'public';
    $backup->secondary_disk = 'local';

    $storage = new StorageSettings;
    $storage->disk = 'local';

    expect($backup->secondaryCollidesWithMediaDisk($storage))->toBeTrue();
});

it('secondary does not collide with primary when unset', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 'local';

    expect($backup->secondaryCollidesWithPrimary())->toBeFalse();
});

it('secondary collides with primary when they resolve to the same destination', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 's3';
    $backup->s3_bucket = 'shared';
    $backup->s3_region = 'us-east-1';
    $backup->secondary_disk = 's3-like';
    $backup->secondary_s3_bucket = 'shared';
    $backup->secondary_s3_region = 'us-east-1';

    expect($backup->secondaryCollidesWithPrimary())->toBeTrue();
});

it('secondary does not collide with primary when genuinely different', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 'local';
    $backup->secondary_disk = 'public';

    expect($backup->secondaryCollidesWithPrimary())->toBeFalse();
});

// ── Stage 7: encryption requirement ─────────────────────────────────────────

it('does not require encryption for local/public destinations', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 'local';
    $backup->secondary_disk = 'public';

    expect($backup->requiresEncryption())->toBeFalse()
        ->and($backup->encryptionMisconfigured())->toBeFalse();
});

it('requires encryption when the primary is bucket-based', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 's3';

    expect($backup->requiresEncryption())->toBeTrue()
        ->and($backup->encryptionMisconfigured())->toBeTrue();

    $backup->encryption_password = 'correct-horse-battery-staple';
    expect($backup->encryptionMisconfigured())->toBeFalse();
});

it('requires encryption when only the secondary is bucket-based', function (): void {
    $backup = new BackupSettings;
    $backup->disk = 'local';
    $backup->secondary_disk = 's3-like';

    expect($backup->requiresEncryption())->toBeTrue()
        ->and($backup->encryptionMisconfigured())->toBeTrue();
});
