<?php

declare(strict_types=1);

use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Octane;
use Magna\Content\FieldTypeRegistry;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Guards the Octane config against the two regressions that broke it before:
 *   1. listeners emptied to [] — disables Octane's own per-request state reset
 *      AND silently makes warm/flush do nothing.
 *   2. mutable registries warmed — freezes runtime state across a worker.
 */

it('octane listeners are populated so per-request state reset runs', function (): void {
    $listeners = config('octane.listeners');

    expect($listeners)->toBeArray()->not->toBeEmpty()
        ->and($listeners)->toHaveKey(RequestReceived::class)
        ->and($listeners[RequestReceived::class])->not->toBeEmpty()
        ->and($listeners)->toHaveKey(OperationTerminated::class)
        ->and($listeners[OperationTerminated::class])->not->toBeEmpty();
});

/*
 * Two different kinds of warming, and the old pin conflated them.
 *
 * Magna's own additions must be immutable registries - that half stands. But
 * ApplicationFactory::warm() reads this list INSTEAD of Octane's defaults,
 * not in addition to them, and the pin `toBe([FieldTypeRegistry::class])`
 * enforced the loss: `auth` was never pre-resolved into the base container,
 * so on any worker where no boot-time code happened to touch it, the Gate's
 * resolver - a closure over the container that built it - asked a second,
 * userless AuthManager while the request carried a signed-in user. Every
 * policy then denied as if for a guest: "This action is unauthorized." to
 * people who plainly held the ability, a core super admin included,
 * per-worker, healed by recycling. Octane's service list is about container
 * RESIDENCY, not mutability; dropping entries from it changes which container
 * answers, not what state survives.
 */
it('octane warms its own service defaults plus the immutable registry, and flushes nothing', function (): void {
    $warm = config('octane.warm');

    expect($warm)->toBeArray();

    foreach (Octane::defaultServicesToWarm() as $service) {
        expect($warm)->toContain($service);
    }

    // The one that was lost, by name: the gate resolves its user through it.
    expect($warm)->toContain('auth')
        ->and($warm)->toContain(FieldTypeRegistry::class)
        ->and(config('octane.flush'))->toBe([]);
});
