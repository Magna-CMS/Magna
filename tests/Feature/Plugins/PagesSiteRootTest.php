<?php

declare(strict_types=1);

/**
 * Handing the domain root to the site, from Site settings.
 *
 * Magna serves the admin panel at "/", so a site's home page was only ever
 * reachable at its own slug — /home, never /. The switch that fixes that is
 * core's (a panel cannot be relocated by a plugin: Filament builds its routes
 * from AdminPanelProvider during register(), long before any plugin boots),
 * but it is OFFERED here, because freeing the root only means anything on a
 * site that has a frontend to put there.
 *
 * What these tests hold: the toggle reflects and writes the core setting, and
 * saving the rest of the page never depends on it.
 */

use Magna\Admin\PanelPath;
use Magna\Admin\PanelSettings;
use Magna\Auth\Role;
use Magna\Pages\Filament\Pages\PagesSettingsPage;
use Magna\Pages\PagesSettings;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function siteRootAdmin(): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    // PanelPath refuses to read the database before installation.
    config(['magna.installed_override' => true]);

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.settings');
    $user->assignRole($role);

    return $user;
}

it('offers the root to the site and moves the panel to /admin', function (): void {
    $this->actingAs(siteRootAdmin());

    expect(PanelPath::enabled())->toBeFalse();

    Livewire::test(PagesSettingsPage::class)
        ->assertSet('data.admin_prefix', false)
        ->set('data.admin_prefix', true)
        ->call('save')
        ->assertRedirect('/admin');

    expect(PanelPath::enabled())->toBeTrue()
        ->and(PanelPath::current())->toBe('admin');
});

it('takes the root back when the toggle goes off again', function (): void {
    $this->actingAs(siteRootAdmin());

    $settings = PanelSettings::get();
    $settings->admin_prefix = true;
    $settings->save();

    Livewire::test(PagesSettingsPage::class)
        ->assertSet('data.admin_prefix', true)
        ->set('data.admin_prefix', false)
        ->call('save')
        ->assertRedirect('/');

    expect(PanelPath::enabled())->toBeFalse();
});

it('leaves the panel alone, and does not redirect, when only site settings changed', function (): void {
    $this->actingAs(siteRootAdmin());

    Livewire::test(PagesSettingsPage::class)
        ->set('data.maintenance_mode', true)
        ->call('save')
        ->assertNoRedirect();

    expect(PagesSettings::get()->maintenance_mode)->toBeTrue()
        ->and(PanelPath::enabled())->toBeFalse();
});

it('saves this plugin\'s own settings even when the panel is moving', function (): void {
    $this->actingAs(siteRootAdmin());

    // The panel move happens last on purpose: it redirects, and a redirect
    // must never be able to cost the operator the rest of the form.
    Livewire::test(PagesSettingsPage::class)
        ->set('data.maintenance_mode', true)
        ->set('data.admin_prefix', true)
        ->call('save');

    expect(PagesSettings::get()->maintenance_mode)->toBeTrue()
        ->and(PanelPath::enabled())->toBeTrue();
});
