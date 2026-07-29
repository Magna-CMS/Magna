<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

uses(TestCase::class);

function scheduledEventFor(string $needle): ?object
{
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, $needle) || str_contains((string) $event->description, $needle)) {
            return $event;
        }
    }

    return null;
}

it('magna:publish:scheduled runs every minute without overlapping', function (): void {
    $event = scheduledEventFor('magna:publish:scheduled');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('magna:updater:check guards itself against overlap', function (): void {
    $event = scheduledEventFor('magna:updater:check');

    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue();
});
