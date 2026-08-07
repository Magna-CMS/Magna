<?php

declare(strict_types=1);

/**
 * The menus admin screen: create menus, edit the two-level tree, persist
 * through MenuManager (history snapshot included), permission-gated.
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
        ->call('addItem')
        ->set('items.0.label', 'About us')
        ->set('items.0.type', 'page')
        ->set('items.0.page_id', (string) $about->getKey())
        ->call('addItem', 0)
        ->set('items.0.children.0.label', 'Nested link')
        ->set('items.0.children.0.url', '/nested')
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
        ->assertSet('items.0.label', 'First')
        ->call('moveItem', 0, 1)
        ->assertSet('items.0.label', 'Second')
        ->call('removeItem', 1)
        ->call('save');

    $resolved = $manager->resolve('footer');
    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['label'])->toBe('Second');
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
