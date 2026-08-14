<?php

declare(strict_types=1);

/**
 * What one render is allowed to spend on plugin resolvers.
 *
 * A dynamic tag or a data source is third-party code running inside a
 * visitor's page render. Throwing already degrades to a gap; this covers the
 * one that is merely slow, and the page that asks for more resolvers than
 * anybody intended.
 */

use Magna\Blocks\Resolution\ResolverBudget;
use Tests\TestCase;

uses(TestCase::class);

it('runs a resolver and returns what it produced', function (): void {
    $budget = new ResolverBudget(maxInvocations: 10, maxMilliseconds: 1000);

    expect($budget->spend('tag', 'site.year', fn (): string => '2026'))->toBe('2026')
        ->and($budget->invocations())->toBe(1)
        ->and($budget->exhausted())->toBeFalse();
});

it('refuses everything after the invocation ceiling', function (): void {
    $budget = new ResolverBudget(maxInvocations: 2, maxMilliseconds: 10_000);

    expect($budget->spend('tag', 'a', fn (): string => 'first'))->toBe('first')
        ->and($budget->spend('tag', 'b', fn (): string => 'second'))->toBe('second')
        ->and($budget->exhausted())->toBeTrue();

    $ran = false;
    $refused = $budget->spend('tag', 'c', function () use (&$ran): string {
        $ran = true;

        return 'third';
    });

    // Null, not an exception: the caller degrades to the same gap it renders
    // for a tag that is not registered.
    expect($refused)->toBeNull()
        // And the refused resolver never ran at all — the point is not
        // spending the time, not reporting it afterwards.
        ->and($ran)->toBeFalse()
        ->and($budget->invocations())->toBe(2);
});

it('refuses everything after the wall-clock ceiling', function (): void {
    $budget = new ResolverBudget(maxInvocations: 100, maxMilliseconds: 20);

    $budget->spend('source', 'slow.feed', function (): array {
        usleep(40_000);

        return [];
    });

    expect($budget->exhausted())->toBeTrue()
        ->and($budget->elapsedMilliseconds())->toBeGreaterThan(20.0)
        ->and($budget->spend('source', 'next.feed', fn (): array => [['title' => 'Never fetched']]))
        ->toBeNull();
});

it('charges a resolver that throws for the time it burnt', function (): void {
    $budget = new ResolverBudget(maxInvocations: 5, maxMilliseconds: 20);

    // A resolver that takes two seconds and then fails has cost the visitor
    // exactly as much as one that took two seconds and succeeded.
    expect(static fn () => $budget->spend('tag', 'broken', function (): string {
        usleep(30_000);

        throw new RuntimeException('upstream is down');
    }))->toThrow(RuntimeException::class);

    expect($budget->invocations())->toBe(1)
        ->and($budget->exhausted())->toBeTrue();
});

it('starts again when reset, for a request that renders more than one document', function (): void {
    $budget = new ResolverBudget(maxInvocations: 1, maxMilliseconds: 10_000);

    $budget->spend('tag', 'a', fn (): string => 'first');
    expect($budget->exhausted())->toBeTrue();

    $budget->reset();

    expect($budget->exhausted())->toBeFalse()
        ->and($budget->spend('tag', 'b', fn (): string => 'second'))->toBe('second');
});

it('gives each request its own budget rather than one shared for the process', function (): void {
    // Bound scoped, not singleton: under Octane the container outlives the
    // request, and a shared budget would refuse resolvers on a page that had
    // asked for nothing yet.
    $first = app(ResolverBudget::class);
    $first->spend('tag', 'a', fn (): string => 'x');

    expect(app(ResolverBudget::class)->invocations())->toBe(1);

    app()->forgetScopedInstances();

    expect(app(ResolverBudget::class)->invocations())->toBe(0);
});

it('takes its ceilings from configuration', function (): void {
    config()->set('magna.render_budget.max_resolvers', 1);
    app()->forgetScopedInstances();

    $budget = app(ResolverBudget::class);
    $budget->spend('tag', 'a', fn (): string => 'x');

    expect($budget->exhausted())->toBeTrue();
});
