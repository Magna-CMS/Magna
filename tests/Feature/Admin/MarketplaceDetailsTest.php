<?php

declare(strict_types=1);

/**
 * "See more" on a marketplace card opens the full store listing — icon,
 * publisher, stats, screenshots, description, ratings and reviews, links —
 * on both the Plugins catalog and the Themes Browse tab.
 *
 * Every field is publisher-supplied, so the hostile cases matter as much as
 * the happy one: raw HTML in a description is escaped, javascript: links and
 * image URLs never reach the page.
 */

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Magna\Admin\Pages\PluginsPage;
use Magna\Admin\Pages\ThemesPage;
use Magna\Auth\Role;
use Magna\Marketplace\ListingDetails;
use Magna\Marketplace\Marketplace;
use Magna\Marketplace\MarketplaceClient;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);
});

function detailsAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

/** @return array<string, mixed> a GET /plugins/{vendor}/{package} reply */
function detailsPayload(array $overrides = []): array
{
    return array_merge([
        'package' => 'acme/aurora',
        'name' => 'Aurora',
        'shortDescription' => 'A luminous editorial theme.',
        'version' => '1.2.0',
        'compat' => '^1.0',
        'productType' => 'theme',
        'pricingModel' => 'free',
        'claimable' => true,
        'icon' => 'https://market.example/storage/icons/aurora.png',
        'cover' => 'https://market.example/storage/shots/1.png',
        'categories' => ['content', 'developer-tools'],
        'author' => 'Acme',
        'publisher' => 'Acme Studio',
        'installs' => 12400,
        'rating' => 4.5,
        'ratingsCount' => 4,
        'ratingBreakdown' => ['5' => 3, '4' => 0, '3' => 0, '2' => 0, '1' => 1],
        'website' => 'https://acme.example',
        'docsUrl' => 'https://docs.acme.example',
        'supportEmail' => 'help@acme.example',
        'videoUrl' => 'https://video.example/aurora',
        'releasedAt' => '2026-10-01T10:00:00+00:00',
        'description' => "## Built for writers\n\nCalm typography and **dark mode**.",
        'screenshots' => ['https://market.example/storage/shots/1.png', 'https://market.example/storage/shots/2.png'],
        'reviews' => [
            ['author' => 'Priya', 'stars' => 5, 'review' => 'Gorgeous and fast.', 'date' => '2026-10-10T09:00:00+00:00'],
        ],
    ], $overrides);
}

function fakeMarketplace(array $detail, array $catalog = []): void
{
    Http::fake([
        Marketplace::API_BASE.'/plugins/'.$detail['package'].'*' => Http::response($detail),
        Marketplace::API_BASE.'/plugins*' => Http::response($catalog),
        '*' => Http::response([], 404),
    ]);
}

it('opens a theme\'s full listing from the Browse tab, with the card\'s own install button', function (): void {
    $this->actingAs(detailsAdmin());
    $detail = detailsPayload();
    Cache::put(Marketplace::CACHE_KEY.'.themes', [$detail], 3600);
    fakeMarketplace($detail);

    Livewire::test(ThemesPage::class)
        ->call('setTab', 'browse')
        ->assertSee('See more')
        ->call('showDetails', 'acme/aurora')
        ->assertSet('details.package', 'acme/aurora')
        ->assertSee('About this theme')
        ->assertSee('Acme Studio')
        ->assertSee('Built for writers')
        ->assertSee('<strong>dark mode</strong>', false)
        ->assertSee('https://market.example/storage/shots/2.png', false)
        ->assertSee('Gorgeous and fast.')
        ->assertSee('12K+')
        ->assertSee('Ratings and reviews')
        ->assertSee('help@acme.example')
        ->assertSee('Get &amp; install', false)
        ->call('closeDetails')
        ->assertSet('details', null)
        ->assertDontSee('About this theme');
});

it('opens a plugin\'s listing from the catalog, permissions and review button included', function (): void {
    $this->actingAs(detailsAdmin());
    $detail = detailsPayload([
        'package' => 'acme/forum', 'name' => 'Acme Forum', 'productType' => 'plugin',
        'permissions' => ['content.read', 'users.read'], 'claimable' => null,
    ]);
    fakeMarketplace($detail, [$detail]);

    Livewire::test(PluginsPage::class)
        ->call('showDetails', 'acme/forum')
        ->assertSee('About this plugin')
        ->assertSee('users.read')
        ->assertSee('Write a review');
});

it('escapes raw HTML and drops unsafe links and image URLs from a listing', function (): void {
    $this->actingAs(detailsAdmin());
    fakeMarketplace(detailsPayload([
        'description' => "<script>alert('x')</script>\n\n[click me](javascript:alert(1)) <img src=x onerror=alert(2)>",
        'screenshots' => ['javascript:alert(3)', 'https://market.example/ok.png', 'data:image/png;base64,AAA'],
        'website' => 'javascript:alert(4)',
        'icon' => 'javascript:alert(5)',
        'reviews' => [['author' => '<b>Mallory</b>', 'stars' => 9, 'review' => '<script>x</script>']],
    ]));

    $page = Livewire::test(ThemesPage::class)->call('showDetails', 'acme/aurora');

    $html = $page->html();

    expect($html)->not->toContain('<script>alert')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->not->toContain('javascript:alert')
        ->and($html)->not->toContain('data:image/png')
        ->and($html)->not->toContain('<b>Mallory</b>')
        ->and($html)->toContain('https://market.example/ok.png')
        ->and($page->get('details.screenshots'))->toBe(['https://market.example/ok.png'])
        ->and($page->get('details.reviews.0.stars'))->toBe(5);
});

it('says so instead of opening an empty view when the marketplace cannot be reached', function (): void {
    $this->actingAs(detailsAdmin());
    Http::fake(['*' => Http::response([], 500)]);

    Livewire::test(ThemesPage::class)
        ->call('showDetails', 'acme/aurora')
        ->assertSet('details', null)
        ->assertNotified('Could not load the details');
});

it('refuses a package name that is not vendor/name without asking the marketplace', function (): void {
    Http::fake();

    expect(app(MarketplaceClient::class)->details('../../etc/passwd'))->toBeNull();
    Http::assertNothingSent();
});

it('renders a description as escaped Markdown', function (): void {
    $html = (string) ListingDetails::descriptionHtml("# Title\n\n<iframe src=x></iframe>\n\n[ok](https://ok.example)");

    expect($html)->toContain('<h1>Title</h1>')
        ->and($html)->toContain('&lt;iframe')
        ->and($html)->toContain('href="https://ok.example"');
});
