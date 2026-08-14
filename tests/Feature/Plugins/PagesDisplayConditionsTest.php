<?php

declare(strict_types=1);

/**
 * Display conditions from a plugin (Phase C item 2, RegistersDisplayConditions):
 * a node carrying `{"type": "<handle>"}` is shown or hidden by the condition a
 * plugin registered, and that condition's declared cacheability governs the
 * shared page cache exactly as the two built-in types do.
 *
 * The two things worth proving hardest are both refusals. A type nobody
 * registered hides its node AND makes the page uncacheable — content gated by
 * a rule this installation cannot evaluate must not leak, and a rule whose
 * cache behaviour we cannot promise must not be promised. A condition that
 * throws is treated identically, because a plugin failing mid-render must
 * never be a way to make gated content appear.
 */

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Magna\Blocks\Conditions\ConditionVerdict;
use Magna\Blocks\Conditions\DisplayCondition;
use Magna\Blocks\Conditions\DisplayConditionRegistry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\BuilderBootstrap;
use Magna\Pages\Render\Conditions\ConditionEvaluator;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

/** Cacheable and true — a rule about the page, not about the visitor. */
final class FixtureAlwaysCondition implements DisplayCondition
{
    public function handle(): string
    {
        return 'fixture.always';
    }

    public function label(): string
    {
        return 'Always';
    }

    public function evaluate(array $condition, ?Authenticatable $user, Carbon $now): ConditionVerdict
    {
        return ConditionVerdict::visible();
    }
}

/** Reads something about the visitor, so it must keep the page out of the cache. */
final class FixtureBasketCondition implements DisplayCondition
{
    public function handle(): string
    {
        return 'fixture.basket';
    }

    public function label(): string
    {
        return 'Basket has items';
    }

    public function evaluate(array $condition, ?Authenticatable $user, Carbon $now): ConditionVerdict
    {
        return ConditionVerdict::perVisitor(visible: ($condition['items'] ?? 0) > 0);
    }
}

/** True until a moment it names — cacheable, but only that long. */
final class FixtureSaleCondition implements DisplayCondition
{
    public function handle(): string
    {
        return 'fixture.sale';
    }

    public function label(): string
    {
        return 'Sale is running';
    }

    public function evaluate(array $condition, ?Authenticatable $user, Carbon $now): ConditionVerdict
    {
        return ConditionVerdict::until(visible: true, expiresAt: $now->copy()->addHour());
    }
}

final class FixtureBrokenCondition implements DisplayCondition
{
    public function handle(): string
    {
        return 'fixture.broken';
    }

    public function label(): string
    {
        return 'Broken';
    }

    public function evaluate(array $condition, ?Authenticatable $user, Carbon $now): ConditionVerdict
    {
        throw new RuntimeException('resolver exploded');
    }
}

beforeEach(function (): void {
    $this->enablePlugin('magna/pages');

    /** @var DisplayConditionRegistry $registry */
    $registry = app(DisplayConditionRegistry::class);

    $registry->register(new FixtureAlwaysCondition);
    $registry->register(new FixtureBasketCondition);
    $registry->register(new FixtureSaleCondition);
    $registry->register(new FixtureBrokenCondition);

    $this->evaluate = fn (array $conditions, ?Authenticatable $user = null) => app(ConditionEvaluator::class)
        ->evaluate(['conditions' => $conditions], $user, Carbon::parse('2026-08-14 12:00:00'));
});

it('shows a node through a condition a plugin registered', function (): void {
    $outcome = ($this->evaluate)([['type' => 'fixture.always']]);

    expect($outcome->visible)->toBeTrue()
        ->and($outcome->cacheable)->toBeTrue();
});

it('hides a node when the plugin says no', function (): void {
    $outcome = ($this->evaluate)([['type' => 'fixture.basket', 'items' => 0]]);

    expect($outcome->visible)->toBeFalse();
});

/*
 * The declaration is the whole protection: without it one visitor's variant
 * is stored and served to everybody who asks next.
 */
it('keeps a page carrying a per-visitor condition out of the shared cache', function (): void {
    $outcome = ($this->evaluate)([['type' => 'fixture.basket', 'items' => 3]]);

    expect($outcome->visible)->toBeTrue()
        ->and($outcome->cacheable)->toBeFalse();
});

it('carries a condition’s expiry through, so the cache turns over with it', function (): void {
    $outcome = ($this->evaluate)([['type' => 'fixture.sale']]);

    expect($outcome->cacheable)->toBeTrue()
        ->and($outcome->expiresAt?->toDateTimeString())->toBe('2026-08-14 13:00:00');
});

// A type nobody registered — a plugin somebody disabled, or a newer builder.
it('hides an unregistered type and refuses to cache the page', function (): void {
    $outcome = ($this->evaluate)([['type' => 'nobody.registered.this']]);

    expect($outcome->visible)->toBeFalse()
        ->and($outcome->cacheable)->toBeFalse();
});

it('treats a condition that throws exactly as one it cannot evaluate', function (): void {
    $outcome = ($this->evaluate)([['type' => 'fixture.broken']]);

    expect($outcome->visible)->toBeFalse()
        ->and($outcome->cacheable)->toBeFalse();
});

/*
 * Every condition on a node must pass. A plugin condition cannot talk a
 * built-in one round, and the earliest expiry is the one that counts — the
 * first answer to turn makes the whole node wrong.
 */
it('requires every condition to pass, plugin and built-in alike', function (): void {
    $outcome = ($this->evaluate)([
        ['type' => 'fixture.always'],
        ['type' => 'auth', 'show' => 'authenticated'],
    ]);

    expect($outcome->visible)->toBeFalse();
});

it('keeps the earliest expiry when several conditions name one', function (): void {
    $outcome = ($this->evaluate)([
        ['type' => 'fixture.sale'],
        ['type' => 'schedule', 'until' => '2026-08-14 12:30:00'],
    ]);

    expect($outcome->expiresAt?->toDateTimeString())->toBe('2026-08-14 12:30:00');
});

/*
 * The built-ins are matched before the registry and cannot be replaced: the
 * page cache's own reasoning is built on them, and a plugin redefining either
 * could quietly make every cached page wrong.
 */
it('does not let a plugin redefine a built-in type', function (): void {
    /** @var DisplayConditionRegistry $registry */
    $registry = app(DisplayConditionRegistry::class);

    $registry->register(new class implements DisplayCondition
    {
        public function handle(): string
        {
            return 'auth';
        }

        public function label(): string
        {
            return 'Hijacked';
        }

        public function evaluate(array $condition, ?Authenticatable $user, Carbon $now): ConditionVerdict
        {
            return ConditionVerdict::visible();
        }
    });

    // Guest, asked for authenticated-only: the built-in still answers no.
    $outcome = ($this->evaluate)([['type' => 'auth', 'show' => 'authenticated']]);

    expect($outcome->visible)->toBeFalse();
});

/*
 * The builder offers exactly what the renderer will honour.
 *
 * A picker with a list of its own would eventually offer a condition the
 * evaluator refuses — which looks to whoever set it like the rule quietly
 * not working, since a refused type hides the node.
 */
it('ships every condition it can evaluate to the builder', function (): void {
    $page = app(EntryManager::class)->create('page', [
        'title' => 'Conditions',
        'slug' => 'conditions',
        'status' => 'draft',
    ]);

    $payload = app(BuilderBootstrap::class)->forEntry($page, null);

    $conditions = $payload['displayConditions'];

    $handles = array_column($conditions, 'handle');

    expect($handles)->toContain('auth')
        ->toContain('schedule')
        ->toContain('fixture.basket')
        ->and($conditions[0]['builtIn'])->toBeTrue();

    $plugin = collect($conditions)->firstWhere('handle', 'fixture.basket');

    expect($plugin['label'])->toBe('Basket has items')
        ->and($plugin['builtIn'])->toBeFalse();
});

it('leaves a node with no conditions alone', function (): void {
    $outcome = app(ConditionEvaluator::class)->evaluate([], null, Carbon::now());

    expect($outcome->visible)->toBeTrue()
        ->and($outcome->cacheable)->toBeTrue();
});
