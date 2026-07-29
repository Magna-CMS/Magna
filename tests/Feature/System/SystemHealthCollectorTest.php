<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Magna\System\SystemHealthCollector;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Covers the system-diagnostics logic extracted out of SystemInfoPage so it is
// verified independently of the Filament page that renders it.

beforeEach(function (): void {
    $this->collector = new SystemHealthCollector;
});

it('reports the database driver and a version string', function (): void {
    expect($this->collector->dbDriver())->toBeString()->not->toBe('')
        ->and($this->collector->dbVersion())->toBeString();
});

it('reports cache status ok and a numeric round-trip latency', function (): void {
    expect($this->collector->cacheStatus())->toBe('ok')
        ->and($this->collector->cacheLatencyMs())->toBeFloat();
});

it('returns a shaped backup-health summary', function (): void {
    $health = $this->collector->backupHealth();

    expect($health)->toHaveKeys(['color', 'label'])
        ->and($health['color'])->toBeIn(['ok', 'warning', 'neutral'])
        ->and($health['label'])->toBeString();
});

it('emits no performance warnings outside production', function (): void {
    app()->detectEnvironment(fn (): string => 'testing');

    expect($this->collector->performanceWarnings())->toBe([]);
});

it('returns a positive boot time and an opcache summary shape', function (): void {
    expect($this->collector->bootTimeMs())->toBeFloat()->toBeGreaterThanOrEqual(0.0)
        ->and($this->collector->opcacheStatus())->toHaveKeys(['available', 'enabled', 'hit_rate']);
});

it('reports how long the oldest queued job has waited', function (): void {
    config(['queue.default' => 'database']);

    $health = app(SystemHealthCollector::class);

    // Nothing waiting is not the same as "cannot tell".
    expect($health->queueOldestPendingMinutes())->toBeNull();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subHours(3)->getTimestamp(),
        'created_at' => now()->subHours(3)->getTimestamp(),
    ]);

    // Three hours of waiting is what tells an admin no worker is running —
    // the count alone would look identical to a healthy queue mid-stride.
    expect($health->queueOldestPendingMinutes())->toBeGreaterThanOrEqual(179)
        ->and($health->queuePendingCount())->toBe(1);
});

it('does not claim a queue age it cannot measure', function (): void {
    // The jobs table only exists for the database driver; anything else has
    // no backlog to read, and guessing zero would be a lie that reads as health.
    config(['queue.default' => 'sync']);

    expect(app(SystemHealthCollector::class)->queueOldestPendingMinutes())->toBeNull();
});
