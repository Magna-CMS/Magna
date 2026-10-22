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
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Admin\Pages\ThemesPage;
use Magna\Auth\Role;
use Magna\Licensing\LicenseClient;
use Magna\Licensing\LicenseInstaller;
use Magna\Marketplace\Marketplace;
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

it('installs the licence the claim returned, not merely the newest wallet row', function (): void {
    $this->actingAs(freeThemeAdmin());

    // A repeat claim: the marketplace returns the usable licence the account
    // already holds (7) while an older, revoked one (9) sits first in the wallet.
    $client = $this->mock(LicenseClient::class);
    $client->shouldReceive('claimFree')->once()
        ->andReturn(['ok' => true, 'created' => false, 'license' => ['id' => 7, 'product_slug' => 'magna-cms/eva']]);
    $client->shouldReceive('forgetCache');
    $client->shouldReceive('wallet')->andReturn([
        ['id' => 9, 'product_slug' => 'magna-cms/eva', 'status' => 'revoked'],
        ['id' => 7, 'product_slug' => 'magna-cms/eva', 'status' => 'active'],
    ]);

    $installer = $this->mock(LicenseInstaller::class);
    $installer->shouldReceive('installLicense')->once()->with(7, 'magna-cms/eva')->andReturn('Eva installed.');

    Livewire::test(ThemesPage::class)->call('getFree', 'magna-cms/eva');
});

it('offers Get & install only for a theme the marketplace says can be claimed', function (): void {
    $this->actingAs(freeThemeAdmin());
    Cache::flush();

    $entry = fn (string $package, string $name, ?bool $claimable): array => array_filter([
        'package' => $package, 'name' => $name, 'shortDescription' => 'A theme.', 'version' => '1.0.0',
        'compat' => '^1.0', 'productType' => 'theme', 'pricingModel' => 'free', 'claimable' => $claimable,
    ], fn ($v) => $v !== null);

    Cache::put(Marketplace::CACHE_KEY.'.themes', [
        $entry('acme/ready', 'Ready Theme', true),
    ], 3600);

    Livewire::test(ThemesPage::class)->call('setTab', 'browse')
        ->assertSee('Ready Theme')->assertSee('Get &amp; install', false);

    Cache::put(Marketplace::CACHE_KEY.'.themes', [
        $entry('acme/waiting', 'Waiting Theme', false),
    ], 3600);

    Livewire::test(ThemesPage::class)->call('setTab', 'browse')
        ->assertSee('Waiting Theme')->assertSee('not available to install yet')->assertDontSee('Get &amp; install', false);

    // A marketplace that predates claims: keep the old wording, no button.
    Cache::put(Marketplace::CACHE_KEY.'.themes', [
        $entry('acme/legacy', 'Legacy Theme', null),
    ], 3600);

    Livewire::test(ThemesPage::class)->call('setTab', 'browse')
        ->assertSee('install from your Magna Account')->assertDontSee('Get &amp; install', false);
});
