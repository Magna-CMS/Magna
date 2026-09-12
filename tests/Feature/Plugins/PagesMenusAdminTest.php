<?php

declare(strict_types=1);

/**
 * The menus admin screen: create menus, build the tree by ticking items in
 * bulk, arrange it, persist through MenuManager (history snapshot
 * included), permission-gated.
 *
 * The screen edits a FLAT list of rows with a depth each (MenuTree), so the
 * assertions here are about the round trip — what is ticked becomes rows,
 * what the rows say becomes the stored nested tree — rather than about the
 * arranging rules, which have their own specs against MenuTree.
 */

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Pages\Filament\Pages\MenusPage;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function menusAdminUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.settings');
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('creates a menu and saves an edited tree with history', function (): void {
    $user = menusAdminUser();

    $author = User::factory()->create();
    $manager = app(EntryManager::class);
    $about = $manager->create('page', ['title' => 'About', 'slug' => 'about', 'blocks_data' => []], $author->id);
    $manager->publish($about, actorId: $author->id);

    Livewire::actingAs($user)
        ->test(MenusPage::class)
        ->set('newMenuName', 'Primary navigation')
        ->call('createMenu')
        // Tick the page in the Pages panel, then add a custom link and nest
        // it — the two gestures the screen is built around.
        ->set('checkedPages', [(string) $about->getKey()])
        ->call('addCheckedPages')
        ->set('rows.0.label', 'About us')
        ->set('customLinkUrl', '/nested')
        ->set('customLinkLabel', 'Nested link')
        ->call('addCustomLink')
        ->call('indent', 1)
        ->call('save');

    $menu = Menu::query()->where('handle', 'primary_navigation')->firstOrFail();

    $resolved = app(MenuManager::class)->resolve('primary_navigation');
    expect($resolved[0]['label'])->toBe('About us')
        ->and($resolved[0]['url'])->toBe('/about')
        ->and($resolved[0]['children'][0]['label'])->toBe('Nested link');

    expect(DB::table('pages_menu_revisions')->where('menu_id', $menu->id)->count())->toBe(1);
});

it('loads an existing tree, reorders and removes items', function (): void {
    $user = menusAdminUser();

    $manager = app(MenuManager::class);
    $menu = $manager->create('footer', 'Footer');
    $manager->syncItems($menu, [
        ['label' => 'First', 'type' => 'url', 'url' => '/first'],
        ['label' => 'Second', 'type' => 'url', 'url' => '/second'],
    ]);

    Livewire::actingAs($user)
        ->test(MenusPage::class)
        ->call('selectMenu', $menu->id)
        ->assertSet('rows.0.label', 'First')
        ->call('moveDown', 0)
        ->assertSet('rows.0.label', 'Second')
        ->call('removeRow', 1)
        ->call('save');

    $resolved = $manager->resolve('footer');
    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['label'])->toBe('Second');
});

it('renames a menu without moving what points at it', function (): void {
    $user = menusAdminUser();
    $menu = app(MenuManager::class)->create('primary', 'Primary');

    Livewire::actingAs($user)
        ->test(MenusPage::class)
        ->call('selectMenu', $menu->id)
        ->set('menuName', 'Main navigation')
        ->call('renameMenu');

    $menu->refresh();

    // The handle is what a nav block and a theme layout point at, so a
    // rename must not empty every header that was rendering this menu.
    expect($menu->name)->toBe('Main navigation')
        ->and($menu->handle)->toBe('primary');
});

it('deletes a menu and falls back to whatever is left', function (): void {
    $user = menusAdminUser();
    $manager = app(MenuManager::class);
    $doomed = $manager->create('doomed', 'Doomed');
    $manager->create('kept', 'Kept');

    Livewire::actingAs($user)
        ->test(MenusPage::class)
        ->call('selectMenu', $doomed->id)
        ->call('deleteMenu')
        ->assertSet('menuName', 'Kept');

    expect(Menu::query()->pluck('handle')->all())->toBe(['kept']);
});

it('stores the per-item fields the panels collect, and drops the blank ones', function (): void {
    $user = menusAdminUser();
    $menu = app(MenuManager::class)->create('extras', 'Extras');

    Livewire::actingAs($user)
        ->test(MenusPage::class)
        ->call('selectMenu', $menu->id)
        ->set('customLinkUrl', '/docs')
        ->set('customLinkLabel', 'Docs')
        ->call('addCustomLink')
        ->set('rows.0.settings.css_class', 'is-featured')
        // Through the checkbox's own property: it binds a boolean, and the
        // store wants the string "_blank".
        ->set('rows.0.new_tab', true)
        ->call('save');

    $item = $menu->items()->firstOrFail();

    expect($item->settings)->toBe(['css_class' => 'is-featured'])
        ->and($item->target)->toBe('_blank');

    // And they reach the resolved tree the nav block renders.
    $resolved = app(MenuManager::class)->resolve('extras');
    expect($resolved[0]['css_class'])->toBe('is-featured')
        ->and($resolved[0]['title_attr'])->toBeNull();

    // Reopening the menu shows the checkbox ticked, rather than losing it
    // because the editor and the store disagree about what it is called.
    Livewire::actingAs($user)
        ->test(MenusPage::class)
        ->call('selectMenu', $menu->id)
        ->assertSet('rows.0.new_tab', true);
});

it('rejects duplicate menu names and users without the permission', function (): void {
    $user = menusAdminUser();

    app(MenuManager::class)->create('primary', 'Primary');

    Livewire::actingAs($user)
        ->test(MenusPage::class)
        ->set('newMenuName', 'Primary')
        ->call('createMenu');

    expect(Menu::query()->count())->toBe(1);

    $nobody = User::factory()->create();
    $this->actingAs($nobody);
    expect(MenusPage::canAccess())->toBeFalse();
});
