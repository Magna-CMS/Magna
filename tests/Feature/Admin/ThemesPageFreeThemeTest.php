<?php

declare(strict_types=1);

/**
 * Free themes from the Browse tab.
 *
 * A free theme cannot install through Composer the way a free plugin does —
 * sites load themes only from themes/vendor/name, filled from a signed
 * archive the marketplace serves under a licence. So "Get & install" claims
 * a zero-cost licence into the connected Magna Account and installs it
 * through the same path a purchase uses. Before this the Browse tab showed
 * free themes with the words "install from your Magna Account" and nothing
 * that could actually put one there.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Admin\Pages\ThemesPage;
use Magna\Auth\Role;
use Magna\Licensing\LicenseClient;
use Magna\Licensing\LicenseInstaller;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function freeThemeAdmin(bool $connected = true): User
{
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    $settings = AccountCentreSettings::get();
    $settings->connected = $connected;
    $settings->token = $connected ? 'site-account-token' : null;
    $settings->save();

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'settings.manage');
    $user->assignRole($role);

    return $user;
}

it('claims a free theme into the account and installs it in one click', function (): void {
    $this->actingAs(freeThemeAdmin());

    $client = $this->mock(LicenseClient::class);
    $client->shouldReceive('claimFree')->once()->with('magna-cms/eva')
        ->andReturn(['ok' => true, 'license' => ['id' => 42, 'product_slug' => 'magna-cms/eva']]);
    $client->shouldReceive('forgetCache')->once();
    $client->shouldReceive('wallet')->andReturn([['id' => 42, 'product_slug' => 'magna-cms/eva']]);

    $installer = $this->mock(LicenseInstaller::class);
    $installer->shouldReceive('installLicense')->once()->with(42, 'magna-cms/eva')->andReturn('Eva 1.0.0 installed.');

    Livewire::test(ThemesPage::class)
        ->call('getFree', 'magna-cms/eva')
        ->assertNotified('Eva 1.0.0 installed.');
});

it('installs nothing when the marketplace refuses the claim', function (): void {
    $this->actingAs(freeThemeAdmin());

    $client = $this->mock(LicenseClient::class);
    $client->shouldReceive('claimFree')->once()
        ->andReturn(['ok' => false, 'error' => 'free_unavailable', 'message' => 'This product is not available to claim for free.']);

    $installer = $this->mock(LicenseInstaller::class);
    $installer->shouldNotReceive('installLicense');

    Livewire::test(ThemesPage::class)
        ->call('getFree', 'magna-cms/eva')
        ->assertNotified('Could not get this theme');
});

it('asks for a Magna Account before claiming anything', function (): void {
    $this->actingAs(freeThemeAdmin(connected: false));

    $client = $this->mock(LicenseClient::class);
    $client->shouldNotReceive('claimFree');

    Livewire::test(ThemesPage::class)->call('getFree', 'magna-cms/eva');
});
