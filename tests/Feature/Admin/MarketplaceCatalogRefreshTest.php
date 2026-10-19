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
use Magna\Updater\UpdateCheck;
use Magna\Updater\UpdateCheckClient;
use Magna\Updater\UpdateCheckResult;
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

/*
 * The Installed tab's "Update now" banner reads update_checks rows, and only
 * a check-in writes those — on a 12-hour schedule. The Refresh button used to
 * rebuild Browse alone, so a theme version published to the marketplace never
 * surfaced as an update until the next scheduled check-in: the one thing the
 * button was pressed for, it did not do. Seen live on a site after a 1.8.1
 * theme release sat invisible behind the schedule.
 */

it('runs an update check-in when the Refresh button is pressed', function (): void {
    $this->actingAs(catalogAdmin());

    Http::fake([
        Marketplace::API_BASE.'/*' => Http::response([]),
    ]);

    $client = Mockery::mock(UpdateCheckClient::class);
    $client->shouldReceive('checkIn')->once()->andReturn(UpdateCheckResult::fromArray([]));
    app()->instance(UpdateCheckClient::class, $client);

    Livewire::test(ThemesPage::class)
        ->call('refreshCatalog', true)
        ->assertNotified();
});

it('reports a found theme update in the refresh notification', function (): void {
    $this->actingAs(catalogAdmin());

    Http::fake([
        Marketplace::API_BASE.'/*' => Http::response([]),
    ]);

    // The row the (mocked) check-in would have written.
    $client = Mockery::mock(UpdateCheckClient::class);
    $client->shouldReceive('checkIn')->once()->andReturnUsing(function () {
        UpdateCheck::query()->create([
            'type' => 'theme',
            'slug' => 'testv/fixture',
            'current_version' => '1.0.0',
            'latest_version' => '1.8.1',
            'update_available' => true,
            'checked_at' => now(),
        ]);

        return UpdateCheckResult::fromArray([]);
    });
    app()->instance(UpdateCheckClient::class, $client);

    Livewire::test(ThemesPage::class)
        ->call('refreshCatalog', true)
        ->assertNotified('Catalog refreshed — 0 themes in the marketplace.');
});

it('degrades to a warning when the check-in cannot reach the manager', function (): void {
    $this->actingAs(catalogAdmin());

    Http::fake([
        Marketplace::API_BASE.'/*' => Http::response([]),
    ]);

    $client = Mockery::mock(UpdateCheckClient::class);
    $client->shouldReceive('checkIn')->once()->andReturnNull();
    app()->instance(UpdateCheckClient::class, $client);

    Livewire::test(ThemesPage::class)
        ->call('refreshCatalog', true)
        ->assertNotified('Could not reach the marketplace — showing what is known.');
});

it('never checks in on a mere page load', function (): void {
    $this->actingAs(catalogAdmin());

    Cache::put(THEME_CATALOG_KEY, catalogEntry('cached/theme', 'Cached Theme'), 3600);

    $client = Mockery::mock(UpdateCheckClient::class);
    $client->shouldReceive('checkIn')->never();
    app()->instance(UpdateCheckClient::class, $client);

    Http::preventStrayRequests();

    Livewire::test(ThemesPage::class)->assertOk();
});
