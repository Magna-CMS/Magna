<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Shared hosts run cron but not a supervised worker, so queued jobs piled
// up forever ("The oldest job has waited 94 minutes"). The scheduler now
// drains the queue once a minute when a queue driver is in use.

it('schedules a queue drain when jobs are actually queued somewhere', function (): void {
    config(['queue.default' => 'database']);

    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($commands->contains(fn (string $c): bool => str_contains($c, 'queue:work')
        && str_contains($c, '--stop-when-empty')))->toBeTrue();
});

it('schedules no drain on the sync driver, where nothing is ever queued', function (): void {
    config(['queue.default' => 'sync']);

    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($commands->contains(fn (string $c): bool => str_contains($c, 'queue:work')))->toBeFalse();
});
