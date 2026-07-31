<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Magna\Backup\BackupRun;
use Magna\Backup\BackupSchedule;
use Magna\Settings\BackupSettings;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function scheduleSettings(array $overrides = []): BackupSettings
{
    $settings = BackupSettings::get();
    $settings->enabled = true;
    $settings->frequency = 'daily';
    $settings->run_at = '02:00';

    foreach ($overrides as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();

    return $settings;
}

it('is never due when disabled, regardless of time', function (): void {
    scheduleSettings(['enabled' => false]);
    Carbon::setTestNow('2026-07-20 02:00:00');

    expect(BackupSchedule::isDueNow())->toBeFalse();
});

it('is due at the exact configured run_at minute with no prior scheduled run', function (): void {
    scheduleSettings();
    Carbon::setTestNow('2026-07-20 02:00:00');

    expect(BackupSchedule::isDueNow())->toBeTrue();
});

it('is not due outside the configured run_at minute', function (): void {
    scheduleSettings();
    Carbon::setTestNow('2026-07-20 02:01:00');

    expect(BackupSchedule::isDueNow())->toBeFalse();

    Carbon::setTestNow('2026-07-20 14:00:00');
    expect(BackupSchedule::isDueNow())->toBeFalse();
});

it('does not fire again the same day once a scheduled run already happened', function (): void {
    scheduleSettings();

    BackupRun::create([
        'type' => BackupRun::TYPE_SCHEDULED,
        'status' => BackupRun::STATUS_SUCCESS,
        'started_at' => Carbon::parse('2026-07-20 02:00:00'),
    ]);

    Carbon::setTestNow('2026-07-20 02:00:00');

    expect(BackupSchedule::isDueNow())->toBeFalse();
});

it('fires again the next day after the previous scheduled run', function (): void {
    scheduleSettings();

    BackupRun::create([
        'type' => BackupRun::TYPE_SCHEDULED,
        'status' => BackupRun::STATUS_SUCCESS,
        'started_at' => Carbon::parse('2026-07-19 02:00:00'),
    ]);

    Carbon::setTestNow('2026-07-20 02:00:00');

    expect(BackupSchedule::isDueNow())->toBeTrue();
});

it('ignores manual runs when deciding whether a scheduled run is due', function (): void {
    scheduleSettings();

    BackupRun::create([
        'type' => BackupRun::TYPE_MANUAL,
        'status' => BackupRun::STATUS_SUCCESS,
        'started_at' => Carbon::parse('2026-07-20 02:00:00'),
    ]);

    Carbon::setTestNow('2026-07-20 02:00:00');

    expect(BackupSchedule::isDueNow())->toBeTrue();
});

it('weekly frequency waits 7 days between scheduled runs', function (): void {
    scheduleSettings(['frequency' => 'weekly']);

    BackupRun::create([
        'type' => BackupRun::TYPE_SCHEDULED,
        'status' => BackupRun::STATUS_SUCCESS,
        'started_at' => Carbon::parse('2026-07-16 02:00:00'), // 4 days before the 20th
    ]);

    Carbon::setTestNow('2026-07-20 02:00:00');
    expect(BackupSchedule::isDueNow())->toBeFalse();

    Carbon::setTestNow('2026-07-24 02:00:00'); // 8 days after the last run
    expect(BackupSchedule::isDueNow())->toBeTrue();
});

it('custom cron expression fires only on a matching minute', function (): void {
    // "At 03:30 on day-of-month 1" — very unlikely to collide with test dates otherwise.
    scheduleSettings(['frequency' => 'custom_cron', 'cron_expression' => '30 3 1 * *']);

    Carbon::setTestNow('2026-08-01 03:30:00');
    expect(BackupSchedule::isDueNow())->toBeTrue();

    Carbon::setTestNow('2026-08-02 03:30:00');
    expect(BackupSchedule::isDueNow())->toBeFalse();
});

it('an invalid cron expression never fires and never throws', function (): void {
    scheduleSettings(['frequency' => 'custom_cron', 'cron_expression' => 'not a cron expression']);
    Carbon::setTestNow('2026-07-20 02:00:00');

    expect(BackupSchedule::isDueNow())->toBeFalse();
});
