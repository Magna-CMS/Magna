<?php

declare(strict_types=1);

/**
 * The Browse tab's search box narrows the marketplace themes the same way
 * the Plugins page's Add New search narrows plugins: by name, description
 * or author, case-insensitively.
 */

use Filament\Facades\Filament;
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

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);
    Http::fake(['*' => Http::response([], 404)]);

    Cache::put(Marketplace::CACHE_KEY.'.themes', [
        searchTheme('acme/aurora', 'Aurora', 'A luminous editorial theme.', 'Acme'),
        searchTheme('north/harbour', 'Harbour', 'Calm pages for a small shop.', 'Northwind'),
    ], 3600);

    $role = Role::factory()->create();
    $role->grant('panel.access', 'settings.manage');
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);
});

/** @return array<string, mixed> a catalog row as GET /plugins?type=theme returns it */
function searchTheme(string $package, string $name, string $description, string $author): array
{
    return [
        'package' => $package,
        'name' => $name,
        'shortDescription' => $description,
        'version' => '1.0.0',
        'compat' => '^1.0',
        'productType' => 'theme',
        'pricingModel' => 'free',
        'claimable' => true,
        'author' => $author,
    ];
}

it('narrows the Browse tab by name, description or author', function (): void {
    $page = Livewire::test(ThemesPage::class)
        ->call('setTab', 'browse')
        ->assertSee('Search available themes')
        ->assertSee('Aurora')
        ->assertSee('Harbour');

    $page->set('searchAvailable', 'aurora')->assertSee('Aurora')->assertDontSee('Harbour');
    $page->set('searchAvailable', 'SMALL SHOP')->assertSee('Harbour')->assertDontSee('Aurora');
    $page->set('searchAvailable', 'northwind')->assertSee('Harbour')->assertDontSee('Aurora');
});

it('says nothing matched rather than that the marketplace is empty', function (): void {
    Livewire::test(ThemesPage::class)
        ->call('setTab', 'browse')
        ->set('searchAvailable', 'no such theme')
        ->assertSee('No themes match your search.')
        ->assertDontSee('No themes are available yet.');
});
