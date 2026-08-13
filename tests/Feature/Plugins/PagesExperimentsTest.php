<?php

declare(strict_types=1);

/**
 * A/B sections (Phase D): sections sharing an experiment name are its
 * variants. Both ship in the HTML so the page stays in the SHARED cache —
 * assignment is client-side and sticky. Counters are per variant only;
 * the public endpoint validates names and refuses to invent experiments.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Experiments\ExperimentStat;
use Magna\Pages\Render\PageRenderer;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function experimentUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

function experimentPage(User $author, string $slug): void
{
    $manager = app(EntryManager::class);

    $section = fn (string $variant, string $text): array => [
        'id' => 'sec-ab-'.$variant, 'type' => 'section',
        'settings' => ['experiment' => 'hero-test', 'variant' => $variant],
        'columns' => [[
            'id' => 'col-ab-'.$variant, 'span' => 12, 'settings' => [],
            'blocks' => [['id' => 'blk-ab-'.$variant, 'block' => 'heading', 'settings' => [], 'data' => ['text' => $text]]],
        ]],
    ];

    $entry = $manager->create('page', [
        'title' => 'Landing', 'slug' => $slug,
        'blocks_data' => [$section('a', 'Original headline'), $section('b', 'Bolder headline')],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);
}

it('ships both variants with the assignment runtime and stays cacheable', function (): void {
    $author = experimentUser();
    experimentPage($author, 'ab-landing');

    $html = $this->get('/ab-landing')->assertOk()
        // Both variants in one cached body — the whole point.
        ->assertHeader('X-Magna-Cache', 'miss')
        ->getContent();

    expect($html)->toContain('data-magna-experiment="hero-test"')
        ->toContain('data-magna-variant="a"')
        ->and($html)->toContain('data-magna-variant="b"')
        ->and($html)->toContain('Original headline')
        ->and($html)->toContain('Bolder headline')
        ->and($html)->toContain('magna-ab:');

    // Second visit is a cache HIT: A/B did not make the page per-visitor.
    $this->get('/ab-landing')->assertOk()->assertHeader('X-Magna-Cache', 'hit');

    // Rendering registered both variants for the results screen.
    expect(ExperimentStat::query()->where('experiment', 'hero-test')->count())->toBe(2);
});

it('counts exposures and conversions, and refuses unknown or malformed names', function (): void {
    $author = experimentUser();
    experimentPage($author, 'ab-counted');
    $this->get('/ab-counted')->assertOk();

    $this->postJson('/pages-experiments/track', ['experiment' => 'hero-test', 'variant' => 'a', 'event' => 'exposure'])
        ->assertNoContent();
    $this->postJson('/pages-experiments/track', ['experiment' => 'hero-test', 'variant' => 'a', 'event' => 'conversion'])
        ->assertNoContent();

    $stat = ExperimentStat::query()->where('experiment', 'hero-test')->where('variant', 'a')->firstOrFail();
    expect($stat->exposures)->toBe(1)
        ->and($stat->conversions)->toBe(1)
        ->and($stat->rate())->toBe(100.0);

    // An experiment no document declares cannot be created from outside,
    // and malformed names never reach the table.
    $this->postJson('/pages-experiments/track', ['experiment' => 'invented', 'variant' => 'a', 'event' => 'exposure'])
        ->assertNoContent();
    $this->postJson('/pages-experiments/track', ['experiment' => 'hero-test', 'variant' => '../../etc', 'event' => 'exposure'])
        ->assertNoContent();
    $this->postJson('/pages-experiments/track', ['experiment' => 'hero-test', 'variant' => 'a', 'event' => 'delete'])
        ->assertNoContent();

    expect(ExperimentStat::query()->count())->toBe(2)
        ->and(ExperimentStat::query()->where('variant', 'a')->firstOrFail()->exposures)->toBe(1);
});

it('shows every variant in the builder rather than hiding all but one', function (): void {
    $author = experimentUser();
    experimentPage($author, 'ab-builder');

    $entry = Entry::type('page')->where('slug', 'ab-builder')->firstOrFail();
    $html = app(PageRenderer::class)->render($entry, builderMode: true);

    // You cannot edit what you cannot see: no variant attributes, no
    // hiding runtime, both headlines visible.
    expect($html)->not->toContain('data-magna-variant')
        ->and($html)->toContain('Original headline')
        ->and($html)->toContain('Bolder headline');
});
