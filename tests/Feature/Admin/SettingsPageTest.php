<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
        // Site name/tagline and API settings live elsewhere, not here.
        ->assertDontSee('Site name')
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

it('shows the frontend URL fields when Magna Pages is installed', function (): void {
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
        ->assertSee('Frontend URL')
        ->assertSee('Preview base URL')
        ->assertDontSee('Frontend configuration requires Magna Pages');
});
