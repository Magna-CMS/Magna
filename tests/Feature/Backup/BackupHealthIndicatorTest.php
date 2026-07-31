<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Magna\Admin\Pages\SystemInfoPage;
use Magna\Backup\BackupRun;
use Magna\Settings\BackupSettings;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows never-run when backups are not enabled and none have run', function (): void {
    $settings = BackupSettings::get();
    $settings->enabled = false;
    $settings->save();

    $health = (new SystemInfoPage)->getViewData()['backup_health'];

    expect($health)->toBe(['color' => 'neutral', 'label' => 'Never run (automation disabled)']);
});

// Regression: found live in a real browser session — three successful
// manual runs ("Run backup now" bypasses the enabled toggle by design,
// see BackupSettingsPage's runNow tooltip) were completely hidden behind
// a blanket "Disabled" label. Manual backup history must remain visible
// regardless of whether the *schedule* is turned on.
it('shows the real last-success recency even when automation is disabled, if manual backups exist', function (): void {
    $settings = BackupSettings::get();
    $settings->enabled = false;
    $settings->save();

    Carbon::setTestNow('2026-07-20 12:00:00');
    BackupRun::create(['type' => BackupRun::TYPE_MANUAL, 'status' => BackupRun::STATUS_SUCCESS, 'started_at' => now()->subHour()]);

    $health = (new SystemInfoPage)->getViewData()['backup_health'];

    expect($health['color'])->toBe('ok')
        ->and($health['label'])->toContain('manual only')
        ->and($health['label'])->toContain('automation disabled');
});

it('does not apply the staleness escalation when automation is disabled', function (): void {
    $settings = BackupSettings::get();
    $settings->enabled = false;
    $settings->frequency = 'daily';
    $settings->save();

    Carbon::setTestNow('2026-07-20 12:00:00');
    // Far older than the 2-day daily grace window — would be "stale" if enabled.
    BackupRun::create(['type' => BackupRun::TYPE_MANUAL, 'status' => BackupRun::STATUS_SUCCESS, 'started_at' => now()->subDays(30)]);

    $health = (new SystemInfoPage)->getViewData()['backup_health'];

    expect($health['color'])->toBe('ok'); // no schedule promised, so nothing to be "stale" relative to
});

it('warns when enabled but no successful backup has ever run', function (): void {
    $settings = BackupSettings::get();
    $settings->enabled = true;
    $settings->save();

    $health = (new SystemInfoPage)->getViewData()['backup_health'];

    expect($health['color'])->toBe('warning')
        ->and($health['label'])->toBe('No successful backup yet');
});

it('is ok when the last daily success is recent', function (): void {
    $settings = BackupSettings::get();
    $settings->enabled = true;
    $settings->frequency = 'daily';
    $settings->save();

    Carbon::setTestNow('2026-07-20 12:00:00');
    BackupRun::create(['type' => BackupRun::TYPE_SCHEDULED, 'status' => BackupRun::STATUS_SUCCESS, 'started_at' => now()->subDay()]);

    $health = (new SystemInfoPage)->getViewData()['backup_health'];

    expect($health['color'])->toBe('ok');
});

it('warns when the last daily success is stale', function (): void {
    $settings = BackupSettings::get();
    $settings->enabled = true;
    $settings->frequency = 'daily';
    $settings->save();

    Carbon::setTestNow('2026-07-20 12:00:00');
    BackupRun::create(['type' => BackupRun::TYPE_SCHEDULED, 'status' => BackupRun::STATUS_SUCCESS, 'started_at' => now()->subDays(5)]);

    $health = (new SystemInfoPage)->getViewData()['backup_health'];

    expect($health['color'])->toBe('warning')
        ->and($health['label'])->toContain('stale');
});

it('gives weekly backups a longer grace window than daily', function (): void {
    $settings = BackupSettings::get();
    $settings->enabled = true;
    $settings->frequency = 'weekly';
    $settings->save();

    Carbon::setTestNow('2026-07-20 12:00:00');
    BackupRun::create(['type' => BackupRun::TYPE_SCHEDULED, 'status' => BackupRun::STATUS_SUCCESS, 'started_at' => now()->subDays(5)]);

    $health = (new SystemInfoPage)->getViewData()['backup_health'];
    expect($health['color'])->toBe('ok'); // 5 days is within the 9-day weekly grace window

    BackupRun::query()->delete();
    BackupRun::create(['type' => BackupRun::TYPE_SCHEDULED, 'status' => BackupRun::STATUS_SUCCESS, 'started_at' => now()->subDays(10)]);

    $health = (new SystemInfoPage)->getViewData()['backup_health'];
    expect($health['color'])->toBe('warning'); // 10 days exceeds it
});
