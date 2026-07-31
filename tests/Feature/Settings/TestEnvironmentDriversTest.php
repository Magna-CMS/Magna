<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

/**
 * The suite has to run on the drivers phpunit.xml pins, not on whatever the
 * DB-backed PerformanceSettings happen to say.
 *
 * PerformanceServiceProvider applies those settings to cache.default and
 * queue.default at boot. It skipped that when the `settings` table was absent,
 * which on in-memory SQLite is always — so SQLite runs kept array/sync while
 * MySQL and Postgres runs silently switched to the `database` cache and queue.
 * Twelve backup tests failed there for one reason: RunBackupJob::dispatch()
 * queued the job instead of running it inline, so no BackupRun row was ever
 * written and every later assertion read null.
 *
 * These assertions run on all three drivers in CI, so the day the drivers
 * diverge again this fails everywhere instead of on two thirds of the matrix.
 */
it('keeps the queue driver pinned to sync', function (): void {
    expect(config('queue.default'))->toBe('sync');
});

it('keeps the cache store pinned to array', function (): void {
    expect(config('cache.default'))->toBe('array');
});

it('runs a dispatched job inline rather than queueing it', function (): void {
    Bus::fake();

    // The point is the connection a dispatch resolves to. On the `database`
    // queue this lands in the jobs table and the handler never runs, which is
    // exactly how the backup suite broke.
    expect(Queue::getDefaultDriver())->toBe('sync');
});
