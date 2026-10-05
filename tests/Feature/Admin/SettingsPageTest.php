<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\Admin\Pages\PluginsPage;
use Magna\Admin\Pages\SettingsPage;
use Magna\Auth\Role;
use Magna\Plugins\PluginRecord;
use Magna\Settings\GeneralSettings;
use Magna\Settings\SecuritySettings;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

function settingsSuperAdmin(): User
{
    $role = Role::factory()->create([
        'handle' => 'super_admin',
        'name' => 'Super Admin',
        'is_super_admin' => true,
    ]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('renders all settings sections on one page', function (): void {
    $this->actingAs(settingsSuperAdmin());

    Livewire::test(SettingsPage::class)
        ->assertOk()
        ->assertSee('General')
        ->assertSee('Localization')
        ->assertSee('Email')
        ->assertSee('Storage')
        ->assertSee('URLs & Frontend')
        ->assertSee('Security')
        ->assertSee('Search settings')
        // Site name and tagline are General settings and belong on this
        // screen. The assertion here used to say they lived elsewhere; they
        // lived nowhere, which is why a published site called itself
        // "Magna CMS" until someone reached for tinker.
        ->assertSee('Site name')
        ->assertSee('Tagline')
        // API settings really do live elsewhere.
        ->assertDontSee('Maximum page size');
});

it('saves settings across multiple groups at once', function (): void {
    $this->actingAs(settingsSuperAdmin());

    Livewire::test(SettingsPage::class)
        ->fillForm([
            'default_locale' => 'fr',
            'session_lifetime' => 240,
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(GeneralSettings::get()->default_locale)->toBe('fr')
        ->and(SecuritySettings::get()->session_lifetime)->toBe(240);
});

it('is only accessible with the settings.manage permission', function (): void {
    $this->actingAs(User::factory()->create(['two_factor_confirmed_at' => now()]));

    expect(SettingsPage::canAccess())->toBeFalse();
});

// ── Frontend URL fields follow the Magna Pages plugin ────────────────────────
// Migrated from the deleted UrlSettingsPage's own test file: the unified page
// carries the same conditional — Magna is headless by default, so the
// frontend/preview URL fields only exist once Magna Pages is installed.

it('shows the install-Magna-Pages notice when the plugin is absent', function (): void {
    $this->actingAs(settingsSuperAdmin());

    Livewire::test(SettingsPage::class)
        ->assertOk()
        ->assertSee('Frontend configuration requires Magna Pages')
        ->assertSee('CDN URL')
        ->assertDontSee('Dashboard URL');
});

/**
 * The notice names the plugin that unlocks the tab, so it owes the reader the
 * screen that installs it — "then return here" was the whole instruction, with
 * nowhere to go in between.
 */
it('offers a way to install Magna Pages when it is missing', function (): void {
    $this->actingAs(settingsSuperAdmin());

    Livewire::test(SettingsPage::class)
        ->assertOk()
        ->assertSee('Install Magna Pages')
        ->assertSee(PluginsPage::getUrl());
});

it('shows the frontend URL fields when Magna Pages is installed', function (): void {
    PluginRecord::create([
        'name' => 'magna-cms/pages',
        'display_name' => 'Magna Pages',
        'version' => '1.0.0',
        'enabled' => true,
        'base_path' => sys_get_temp_dir().'/magna-pages',
        'manifest' => ['name' => 'magna-cms/pages'],
    ]);

    $this->actingAs(settingsSuperAdmin());

    Livewire::test(SettingsPage::class)
        ->assertOk()
        ->assertSee('Frontend URL')
        ->assertSee('Preview base URL')
        ->assertDontSee('Frontend configuration requires Magna Pages')
        ->assertDontSee('Install Magna Pages');
});

/**
 * The plugin row is named by its MANIFEST — `magna-cms/pages` — while the
 * directory it lives in is `magna/pages`. Matching the directory is what made
 * every v1.4.6 site read "Frontend configuration requires Magna Pages" with the
 * plugin installed and enabled, because the predicate could never be true. The
 * literal is asserted here so the same confusion cannot return quietly.
 */
it('matches Magna Pages on its manifest name, not its directory', function (): void {
    PluginRecord::create([
        'name' => 'magna/pages',
        'display_name' => 'Magna Pages',
        'version' => '1.0.0',
        'enabled' => true,
        'base_path' => sys_get_temp_dir().'/magna-pages',
        'manifest' => ['name' => 'magna/pages'],
    ]);

    $this->actingAs(settingsSuperAdmin());

    Livewire::test(SettingsPage::class)
        ->assertOk()
        ->assertSee('Frontend configuration requires Magna Pages')
        ->assertDontSee('Preview base URL');
});

it('stores the site name and tagline it was given', function (): void {
    $this->actingAs(settingsSuperAdmin());

    Livewire::test(SettingsPage::class)
        ->assertSet('data.site_name', 'Magna CMS')
        ->set('data.site_name', 'Lekha')
        ->set('data.site_tagline', 'Billing that stays out of the way')
        ->call('save')
        ->assertHasNoErrors();

    $general = GeneralSettings::get();

    expect($general->site_name)->toBe('Lekha')
        ->and($general->site_tagline)->toBe('Billing that stays out of the way');
});

/**
 * The field is required, so an empty name should never reach storage — a site
 * whose name coerced to "" renders a bare separator in every page title
 * instead of saying who it is.
 */
it('keeps the shipped name rather than storing an empty one', function (): void {
    $this->actingAs(settingsSuperAdmin());

    Livewire::test(SettingsPage::class)
        ->set('data.site_name', '')
        ->call('save');

    expect(GeneralSettings::get()->site_name)->toBe('Magna CMS');
});
