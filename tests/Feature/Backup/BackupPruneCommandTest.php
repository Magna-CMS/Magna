<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Magna\Backup\BackupRun;
use Magna\Settings\BackupSettings;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('public');
    Carbon::setTestNow('2026-07-20 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function makeBackupRun(string $daysAgo, string $status = BackupRun::STATUS_SUCCESS): BackupRun
{
    $path = 'Magna CMS/backup-'.$daysAgo.'.zip';
    Storage::disk('public')->put($path, 'not a real zip, just a marker file');

    return BackupRun::create([
        'type' => BackupRun::TYPE_SCHEDULED,
        'status' => $status,
        'disk' => 'public',
        'path' => $path,
        'started_at' => now()->subDays((int) $daysAgo),
        'finished_at' => now()->subDays((int) $daysAgo),
    ]);
}

it('keeps the retention_count most recent backups regardless of age', function (): void {
    $settings = BackupSettings::get();
    $settings->retention_count = 2;
    $settings->retention_days = 1; // everything older than 1 day would otherwise be prunable
    $settings->save();

    $old1 = makeBackupRun('100');
    $old2 = makeBackupRun('50');
    $recent1 = makeBackupRun('10');
    $recent2 = makeBackupRun('5');

    Artisan::call('magna:backup:prune');

    expect(BackupRun::query()->find($recent1->id))->not->toBeNull()
        ->and(BackupRun::query()->find($recent2->id))->not->toBeNull()
        ->and(BackupRun::query()->find($old1->id))->toBeNull()
        ->and(BackupRun::query()->find($old2->id))->toBeNull();

    expect(Storage::disk('public')->exists($old1->path))->toBeFalse()
        ->and(Storage::disk('public')->exists($recent1->path))->toBeTrue();
});

it('keeps backups newer than retention_days even beyond retention_count', function (): void {
    $settings = BackupSettings::get();
    $settings->retention_count = 1; // would only keep the single newest by count alone
    $settings->retention_days = 30;
    $settings->save();

    $withinDays = makeBackupRun('20'); // beyond count=1, but within 30 days
    $newest = makeBackupRun('1');

    Artisan::call('magna:backup:prune');

    expect(BackupRun::query()->find($withinDays->id))->not->toBeNull()
        ->and(BackupRun::query()->find($newest->id))->not->toBeNull();
});

it('prunes a backup only when it is beyond both retention_count and retention_days', function (): void {
    $settings = BackupSettings::get();
    $settings->retention_count = 1;
    $settings->retention_days = 10;
    $settings->save();

    $prunable = makeBackupRun('20'); // beyond count=1 AND beyond 10 days
    $newest = makeBackupRun('1');

    Artisan::call('magna:backup:prune');

    expect(BackupRun::query()->find($prunable->id))->toBeNull()
        ->and(BackupRun::query()->find($newest->id))->not->toBeNull();
});

it('never deletes the single most recent successful backup even with retention misconfigured to 0', function (): void {
    $settings = BackupSettings::get();
    $settings->retention_count = 0;
    $settings->retention_days = 0;
    $settings->save();

    $onlyBackup = makeBackupRun('365');

    Artisan::call('magna:backup:prune');

    expect(BackupRun::query()->find($onlyBackup->id))->not->toBeNull()
        ->and(Storage::disk('public')->exists($onlyBackup->path))->toBeTrue();
});

it('leaves failed and pending runs untouched', function (): void {
    $settings = BackupSettings::get();
    $settings->retention_count = 0;
    $settings->retention_days = 0;
    $settings->save();

    $failed = makeBackupRun('365', BackupRun::STATUS_FAILED);
    makeBackupRun('1');

    Artisan::call('magna:backup:prune');

    expect(BackupRun::query()->find($failed->id))->not->toBeNull();
});
