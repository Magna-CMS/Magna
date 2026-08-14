<?php

declare(strict_types=1);

/**
 * The render budget on the public page path (§B3).
 *
 * A dynamic tag or data source is plugin code running inside a visitor's
 * request. A throwing one already degrades to a gap; this is the ceiling for
 * one that is merely slow. Past it, the remaining resolvers are refused, the
 * page renders the same gaps, and — the part that matters — the degraded copy
 * never enters the shared cache, where it would outlive the slow minute that
 * produced it.
 */

use Magna\Auth\Role;
use Magna\Blocks\DynamicTags\DynamicTag;
use Magna\Blocks\DynamicTags\DynamicTagRegistry;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

final class FixtureSlowTag implements DynamicTag
{
    public function handle(): string
    {
        return 'fixture.slow';
    }

    public function label(): string
    {
        return 'Slow tag';
    }

    public function cacheability(): string
    {
        return self::CACHE_STATIC;
    }

    public function resolve(): string
    {
        usleep(40_000);

        return 'SLOW-VALUE';
    }
}

final class FixtureQuickTag implements DynamicTag
{
    public function handle(): string
    {
        return 'fixture.quick';
    }

    public function label(): string
    {
        return 'Quick tag';
    }

    public function cacheability(): string
    {
        return self::CACHE_STATIC;
    }

    public function resolve(): string
    {
        return 'QUICK-VALUE';
    }
}

function budgetSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
    app(DynamicTagRegistry::class)->register(new FixtureSlowTag);
    app(DynamicTagRegistry::class)->register(new FixtureQuickTag);

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/**
 * A page of heading blocks, one per binding, in order.
 *
 * @param  list<string>  $binds
 */
function budgetPage(User $author, string $slug, array $binds): Entry
{
    $manager = app(EntryManager::class);

    $blocks = [];
    foreach ($binds as $index => $bind) {
        $blocks[] = [
            'id' => "blk-b-{$slug}-{$index}",
            'block' => 'heading',
            'settings' => [],
            'data' => ['text' => ['$bind' => $bind]],
        ];
    }

    $entry = $manager->create('page', [
        'title' => 'Budget page',
        'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-b-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [['id' => 'col-b-'.$slug, 'span' => 12, 'settings' => [], 'blocks' => $blocks]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

/**
 * A fresh budget, as a new request would get.
 *
 * The container is per-request in production — a new process under FPM, an
 * explicit flush under Octane — but survives between calls inside one test,
 * so without this the second request would start already spent.
 */
function nextRequest(): void
{
    app()->forgetScopedInstances();
}

it('refuses the resolvers after the budget is spent and renders them as gaps', function (): void {
    $author = budgetSetup();
    config()->set('magna.render_budget.max_milliseconds', 5);
    nextRequest();

    budgetPage($author, 'over-budget', ['tag.fixture.slow', 'tag.fixture.quick']);

    $html = $this->get('/over-budget')->assertOk()->getContent();

    // The first tag ran — the ceiling bounds what follows, it does not
    // interrupt a call already in flight.
    expect($html)->toContain('SLOW-VALUE')
        // The second was refused, and reads as the same gap an unknown tag
        // renders. A page missing a value is worse than a fast one and much
        // better than a page nobody waits for.
        ->and($html)->not->toContain('QUICK-VALUE');
});

it('keeps a degraded render out of the shared page cache', function (): void {
    $author = budgetSetup();
    config()->set('magna.render_budget.max_milliseconds', 5);
    nextRequest();

    budgetPage($author, 'degraded', ['tag.fixture.slow', 'tag.fixture.quick']);

    // Every request pays the cost and every request is honest about it,
    // rather than one slow request freezing its gaps for everybody.
    $this->get('/degraded')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');
    nextRequest();
    $this->get('/degraded')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');
});

it('leaves a page inside its budget cacheable, exactly as before', function (): void {
    $author = budgetSetup();
    budgetPage($author, 'within-budget', ['tag.fixture.quick']);

    $this->get('/within-budget')->assertOk()->assertHeader('X-Magna-Cache', 'miss');
    nextRequest();

    $response = $this->get('/within-budget')->assertOk()->assertHeader('X-Magna-Cache', 'hit');

    expect($response->getContent())->toContain('QUICK-VALUE');
});
