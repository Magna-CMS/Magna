<?php

declare(strict_types=1);

/**
 * The Themes screen surfaces addons (04-THEMES-V2 §5): a separate section
 * from themes, each addon showing its host, its paired plugins, and
 * whether it currently applies — with no activate button, because addons
 * apply automatically alongside their host.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\Admin\Pages\ThemesPage;
use Magna\Auth\Role;
use Magna\Themes\ThemeManager;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function themesAdmin(): User
{
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'settings.manage');
    $user->assignRole($role);

    return $user;
}

it('lists addons separately with their pairing and applies state', function (): void {
    $this->actingAs(themesAdmin());
    app(ThemeManager::class)->activate('addonhost/base');

    Livewire::test(ThemesPage::class)
        ->assertSee('Theme addons')
        ->assertSee('Pages Kit for Addon Host')
        ->assertSee('Styles magna/pages')
        ->assertSee('For addonhost/base')
        ->assertSee('Applies')          // host active — specific addon applies
        ->assertSee('Any theme');       // the "*" addon
});

it('shows why an addon is waiting when its host theme is not active', function (): void {
    $this->actingAs(themesAdmin());
    // No theme active: the specific addon waits, the "*" addon applies.

    Livewire::test(ThemesPage::class)
        ->assertSee('Waiting for addonhost/base');
});
