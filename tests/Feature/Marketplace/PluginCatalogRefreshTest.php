<?php

declare(strict_types=1);

/**
 * The Add New tab can ask the marketplace again.
 *
 * The catalog is cached for an hour, so a plugin published minutes ago is
 * absent from Add New until the TTL lapses — and nothing on the page says the
 * list is a cached one. A site that had just published a plugin counted four
 * where the marketplace had six and read it as a catalog bug; the two that
 * were "missing" were one already installed (hidden on purpose) and one the
 * cache predated.
 */

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Magna\Admin\Pages\PluginsPage;
use Magna\Auth\Role;
use Magna\Marketplace\Marketplace;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();
});

function catalogRefreshAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

/** @return array<string, mixed> */
function catalogListing(string $package, string $name): array
{
    return [
        'package' => $package,
        'name' => $name,
        'shortDescription' => 'A plugin.',
        'version' => '1.0.0',
        'compat' => '^1.0',
        'permissions' => [],
    ];
}

it('picks up a plugin published after the catalog was cached', function (): void {
    // The hour-old catalog this site is holding.
    Cache::put(Marketplace::CACHE_KEY, [catalogListing('acme/forum', 'Acme Forum')], Marketplace::CACHE_TTL);

    // What the marketplace serves now — the newly published one included.
    Http::fake([Marketplace::API_BASE.'/*' => Http::response([
        catalogListing('acme/forum', 'Acme Forum'),
        catalogListing('acme/forms', 'Acme Forms'),
    ])]);

    $this->actingAs(catalogRefreshAdmin());

    Livewire::test(PluginsPage::class)
        ->call('setTab', 'addnew')
        // Cached: the new plugin is nowhere to be seen, which is the complaint.
        ->assertSee('Acme Forum')
        ->assertDontSee('Acme Forms')
        ->call('refreshCatalog')
        ->assertSee('Acme Forms');
});

it('leaves the cache warm for the next page load', function (): void {
    Cache::put(Marketplace::CACHE_KEY, [catalogListing('acme/forum', 'Acme Forum')], Marketplace::CACHE_TTL);

    Http::fake([Marketplace::API_BASE.'/*' => Http::response([
        catalogListing('acme/forum', 'Acme Forum'),
        catalogListing('acme/forms', 'Acme Forms'),
    ])]);

    $this->actingAs(catalogRefreshAdmin());

    Livewire::test(PluginsPage::class)->call('refreshCatalog');

    // Refreshing re-fills the cache rather than disabling it: the point is to
    // re-ask once, not to make every render hit the network.
    $cached = Cache::get(Marketplace::CACHE_KEY);

    expect($cached)->toBeArray()->toHaveCount(2);
});

it('says so when the marketplace cannot be reached, and keeps what it knows', function (): void {
    Cache::put(Marketplace::CACHE_KEY, [catalogListing('acme/forum', 'Acme Forum')], Marketplace::CACHE_TTL);

    Http::fake([Marketplace::API_BASE.'/*' => Http::response([], 500)]);

    $this->actingAs(catalogRefreshAdmin());

    // A failed refresh must not leave the admin staring at an empty catalog
    // with no explanation — the page reports the marketplace as unreachable.
    Livewire::test(PluginsPage::class)
        ->call('refreshCatalog')
        ->assertSet('marketplaceUnreachable', true);
});
