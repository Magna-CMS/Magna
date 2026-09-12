<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

// Redis does not round-trip a number as a number. RedisStore writes anything
// numeric verbatim instead of serialising it, and reads it back as the string
// "1" — so a probe of int 1 compared with === was false on every working Redis
// install, and the diagnostics panel announced "Cache connection failed" about
// a cache that was fine. The probe is a string now, which every store returns
// unchanged.
it('reports ok on a store that hands values back as strings, the way Redis does', function (): void {
    $stored = null;

    Cache::shouldReceive('put')->once()->andReturnUsing(function (string $key, mixed $value) use (&$stored): bool {
        $stored = $value;

        return true;
    });

    // RedisStore::get(): numeric values come back as the raw string, never an int.
    Cache::shouldReceive('get')->once()->andReturnUsing(function () use (&$stored): mixed {
        return is_numeric($stored) ? (string) $stored : $stored;
    });

    expect((new SystemHealthCollector)->cacheStatus())->toBe('ok');
});

it('reports an error when the value read back is not the one written', function (): void {
    // A store that accepts writes and silently serves something else is broken
    // in the way this check exists to catch — a stale read, a wrong database,
    // a shared key. The probe is random per call so this cannot pass by luck.
    Cache::shouldReceive('put')->once()->andReturnTrue();
    Cache::shouldReceive('get')->once()->andReturn('a value from somewhere else');

    expect((new SystemHealthCollector)->cacheStatus())->toBe('error');
});

it('reports an error when the cache store throws', function (): void {
    Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('Connection refused'));

    expect((new SystemHealthCollector)->cacheStatus())->toBe('error');
});
