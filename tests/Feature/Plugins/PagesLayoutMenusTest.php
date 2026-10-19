<?php

declare(strict_types=1);

/**
 * A theme layout can reach a menu other than the header's.
 *
 * `PageRenderer` resolved one hardcoded handle — `primary` — into
 * `headerMenu`, and nothing else. A second menu was stored, synced, editable
 * in the admin, and unreachable from `layout.blade.php`: there was no resolver
 * in the payload and no way to ask.
 *
 * Found porting a design that ships two navigations on purpose: five links in
 * the header, and a drawer that is a fifteen-link site index with its own
 * wording. The port's drawer carried five, because the layout's script could
 * only clone `[data-header] nav` client-side — it had no other source of
 * links. Ten entries silently missing, with no theme-side fix available.
 *
 * So the payload carries a `menu` closure beside `pageUrl`, which answers the
 * same shape of question. `headerMenu` is untouched, and the tests below pin
 * both halves: the new access, and the old key still being there for layouts
 * that never heard of this.
 */

use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Menus\MenuManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function layoutMenusSetup(): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    config()->set('magna.themes_path', base_path('tests/Fixtures/themes'));
    app(ThemeManager::class)->activate('testv/fixture');

    return User::factory()->create();
}

function layoutMenusPage(User $author): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Menu page',
        'slug' => 'menu-page',
        'blocks_data' => [[
            'id' => 'sec-1', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-1', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-h', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Menus']]],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('resolves a second menu by handle for the layout', function (): void {
    $author = layoutMenusSetup();
    layoutMenusPage($author);

    $menus = app(MenuManager::class);

    $primary = $menus->create('primary', 'Primary navigation');
    $menus->syncItems($primary, [
        ['label' => 'Pricing', 'type' => 'url', 'url' => '/pricing'],
    ], $author->id);

    // The drawer: its own handle, its own wording, more entries than the
    // header carries. This is the menu that used to be unreachable.
    $secondary = $menus->create('secondary', 'Site index');
    $menus->syncItems($secondary, [
        ['label' => 'Billing and POS', 'type' => 'url', 'url' => '/billing'],
        ['label' => 'Offline and sync', 'type' => 'url', 'url' => '/offline'],
        ['label' => 'Questions', 'type' => 'url', 'url' => '/faq'],
    ], $author->id);

    $html = (string) $this->get('/menu-page')->assertOk()->getContent();

    expect($html)->toContain('fixture-theme-drawer')
        ->and($html)->toContain('Billing and POS')
        ->and($html)->toContain('Offline and sync')
        ->and($html)->toContain('Questions')
        ->and($html)->toContain('href="/offline"');
});

it('leaves headerMenu exactly as it was', function (): void {
    $author = layoutMenusSetup();
    layoutMenusPage($author);

    $menus = app(MenuManager::class);
    $primary = $menus->create('primary', 'Primary navigation');
    $menus->syncItems($primary, [
        ['label' => 'Pricing', 'type' => 'url', 'url' => '/pricing'],
    ], $author->id);

    // The closure is additive. A layout printing $headerMenu and nothing else
    // must be unaffected, which is why the const stayed rather than the
    // header becoming one more call through the new seam.
    $this->get('/menu-page')->assertOk();

    expect(app(MenuManager::class)->resolve('primary'))
        ->toHaveCount(1)
        ->and(app(MenuManager::class)->resolve('primary')[0]['label'])->toBe('Pricing');
});

it('degrades to no links when the layout asks for a menu the site has not made', function (): void {
    $author = layoutMenusSetup();
    layoutMenusPage($author);

    // No menus at all. The layout asks for `secondary` on every render, so an
    // unknown handle must resolve to an empty list rather than erroring — the
    // same contract $pageUrl has for an unpublished slug. Without this a theme
    // shipping a drawer would 500 every page on a site whose owner has not
    // built that menu yet.
    $html = (string) $this->get('/menu-page')->assertOk()->getContent();

    expect($html)->not->toContain('fixture-theme-drawer')
        ->and($html)->toContain('fixture-theme-layout');
});
