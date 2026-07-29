<?php

declare(strict_types=1);

use Magna\Content\Events\EntryCreated;
use Magna\Content\Events\EntryDeleted;
use Magna\Content\Events\EntryPublished;
use Magna\Content\Events\EntryUnpublished;
use Magna\Content\Events\EntryUpdated;
use Tests\TestCase;

uses(TestCase::class);

// Locks the payload shape of the content lifecycle events that plugins listen
// to (directly, or via RegistersWebhookEvents). These events are public API:
// a plugin reads $event->entry and $event->actorId. Renaming/removing either
// property is a breaking change and must fail CI. Adding properties is fine.

it('content lifecycle events expose a stable public payload', function (string $event): void {
    expect(class_exists($event))->toBeTrue("Public event {$event} must not be removed or renamed.");

    expect(property_exists($event, 'entry'))->toBeTrue("{$event}::\$entry must remain public API.");
    expect(property_exists($event, 'actorId'))->toBeTrue("{$event}::\$actorId must remain public API.");
})->with([
    EntryCreated::class,
    EntryUpdated::class,
    EntryPublished::class,
    EntryUnpublished::class,
    EntryDeleted::class,
]);
