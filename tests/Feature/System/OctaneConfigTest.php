<?php

declare(strict_types=1);

use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestReceived;
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

it('octane warms only immutable state and flushes nothing (settings self-refresh; schema via middleware)', function (): void {
    expect(config('octane.warm'))->toBe([FieldTypeRegistry::class])
        ->and(config('octane.flush'))->toBe([]);
});
