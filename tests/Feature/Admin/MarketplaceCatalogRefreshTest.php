<?php

declare(strict_types=1);

/**
 * The Refresh button on the Themes screen must actually re-fetch.
 *
 * A catalog response is cached for an hour (Marketplace::CACHE_TTL), so
 * rebuilding the page's snapshot re-read the same cached listing: the button
 * looked busy and changed nothing. Seen live as a theme published to the
 * marketplace minutes earlier still missing from Browse, with no way to
 * correct it short of waiting out the TTL or clearing the cache from a shell.
 * Refresh now passes $fresh and drops the cached copy first; mount() does not,
 * because opening a screen is not a request to re-fetch.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Magna\Admin\Pages\ThemesPage;
use Magna\Auth\Role;
use Magna\Marketplace\Marketplace;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** The key MarketplaceClient::themes() reads — kept apart from the plugin one. */
const THEME_CATALOG_KEY = Marketplace::CACHE_KEY.'.themes';

/** An admin who may open the Themes screen. */
function catalogAdmin(): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'settings.manage');
    $user->assignRole($role);

    return $user;
}

/** The cached shape the page reads: one theme listing, compatible with this core. */
function catalogEntry(string $package, string $name): array
{
    return [[
        'package' => $package,
        'name' => $name,
        'shortDescription' => 'A catalog listing.',
        'version' => '1.0.0',
        'compat' => '^1.0',
        'productType' => 'theme',
    ]];
}

beforeEach(function (): void {
    Cache::flush();
});

it('drops the cached catalog so the Refresh button re-fetches', function (): void {
    $this->actingAs(catalogAdmin());

    Cache::put(THEME_CATALOG_KEY, catalogEntry('stale/theme', 'Stale Theme'), 3600);

    Http::fake([
        Marketplace::API_BASE.'/*' => Http::response(catalogEntry('fresh/theme', 'Fresh Theme')),
    ]);

    Livewire::test(ThemesPage::class)
        ->call('setTab', 'browse')
        ->assertSee('Stale Theme')
        ->call('refreshCatalog', true)
        ->assertSee('Fresh Theme')
        ->assertDontSee('Stale Theme');
});

it('leaves the cached catalog alone when the page is merely opened', function (): void {
    $this->actingAs(catalogAdmin());

    $cached = catalogEntry('cached/theme', 'Cached Theme');

    Cache::put(THEME_CATALOG_KEY, $cached, 3600);

    // Nothing may leave the site on a page load: a stray request would fail
    // loudly rather than quietly re-fetching what the cache already holds.
    Http::preventStrayRequests();

    Livewire::test(ThemesPage::class)
        ->call('setTab', 'browse')
        ->assertSee('Cached Theme');

    expect(Cache::get(THEME_CATALOG_KEY))->toEqual($cached);
});
