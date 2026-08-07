<?php

declare(strict_types=1);

/**
 * Menus: nested trees with append-only history, page items resolving to
 * published URLs (dangling pages degrade silently), the nav block rendering
 * through the resolve seam, and the public menus API for headless frontends.
 */

use Illuminate\Support\Facades\DB;
use Magna\Blocks\BlockRegistry;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Menus\MenuManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function menusSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    return User::factory()->create();
}

function menusPublishPage(User $author, string $title, string $slug): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => $title,
        'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => $title]]],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('syncs a nested tree, snapshots history, and resolves page URLs', function (): void {
    $author = menusSetup();
    $about = menusPublishPage($author, 'About', 'about');
    $manager = app(MenuManager::class);

    $menu = $manager->create('primary', 'Primary navigation');
    $manager->syncItems($menu, [
        ['label' => 'About', 'type' => 'page', 'page_id' => (string) $about->getKey()],
        ['label' => 'External', 'type' => 'url', 'url' => 'https://example.com', 'target' => '_blank', 'children' => [
            ['label' => 'Nested', 'type' => 'url', 'url' => '/nested'],
        ]],
    ], $author->id);

    $resolved = $manager->resolve('primary');

    expect($resolved)->toHaveCount(2)
        ->and($resolved[0]['label'])->toBe('About')
        ->and($resolved[0]['url'])->toBe('/about')
        ->and($resolved[1]['target'])->toBe('_blank')
        ->and($resolved[1]['children'][0]['label'])->toBe('Nested');

    expect(DB::table('pages_menu_revisions')->where('menu_id', $menu->id)->count())->toBe(1);
});

it('silently drops items whose page is unpublished or gone', function (): void {
    $author = menusSetup();
    $draft = app(EntryManager::class)->create('page', [
        'title' => 'Draft only', 'slug' => 'draft-only', 'blocks_data' => [],
    ], $author->id);

    $manager = app(MenuManager::class);
    $menu = $manager->create('footer', 'Footer');
    $manager->syncItems($menu, [
        ['label' => 'Ghost', 'type' => 'page', 'page_id' => (string) $draft->getKey()],
        ['label' => 'Missing', 'type' => 'page', 'page_id' => '01HXNOSUCHPAGEULID00000000'],
        ['label' => 'Kept', 'type' => 'url', 'url' => '/kept'],
    ], $author->id);

    $resolved = $manager->resolve('footer');

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['label'])->toBe('Kept');
});

it('renders the nav block on a public page', function (): void {
    $author = menusSetup();
    $about = menusPublishPage($author, 'About', 'about');

    $manager = app(MenuManager::class);
    $menu = $manager->create('primary', 'Primary');
    $manager->syncItems($menu, [
        ['label' => 'About us', 'type' => 'page', 'page_id' => (string) $about->getKey()],
    ], $author->id);

    $entryManager = app(EntryManager::class);
    $home = $entryManager->create('page', [
        'title' => 'Landing', 'slug' => 'landing',
        'blocks_data' => [[
            'id' => 'sec-nav', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-nav', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-nav', 'block' => 'nav', 'settings' => [], 'data' => ['menu' => 'primary']]],
            ]],
        ]],
    ], $author->id);
    $entryManager->publish($home, actorId: $author->id);

    $this->get('/landing')
        ->assertOk()
        ->assertSee('magna-nav', false)
        ->assertSee('About us')
        ->assertSee('href="/about"', false);
});

it('serves the resolved menu over the public API', function (): void {
    $author = menusSetup();
    $about = menusPublishPage($author, 'About', 'about');

    $manager = app(MenuManager::class);
    $manager->syncItems($manager->create('primary', 'Primary'), [
        ['label' => 'About', 'type' => 'page', 'page_id' => (string) $about->getKey()],
    ], $author->id);

    $this->getJson('/api/v1/pages/menus/primary')
        ->assertOk()
        ->assertJsonPath('handle', 'primary')
        ->assertJsonPath('items.0.label', 'About')
        ->assertJsonPath('items.0.url', '/about');

    $this->getJson('/api/v1/pages/menus/nope')->assertNotFound();
});

it('exposes menus as options for the nav block field', function (): void {
    menusSetup();
    app(MenuManager::class)->create('primary', 'Primary navigation');

    $definition = app(BlockRegistry::class)->get('nav');
    $options = $definition?->field('menu')?->resolveOptions();

    expect($options)->toBe(['primary' => 'Primary navigation']);
});
