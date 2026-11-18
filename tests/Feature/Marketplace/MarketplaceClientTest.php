<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Marketplace\Marketplace;
use Magna\Marketplace\MarketplaceClient;
use Magna\Marketplace\PluginListing;
use Tests\TestCase;

// RefreshDatabase: submitReview()/reportPlugin() now read AccountCentreSettings,
// which persists through the real settings table.
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

/** Marks this site as connected to a Magna Account, as submitReview()/reportPlugin() now require. */
function connectAccountCentre(): void
{
    $settings = AccountCentreSettings::get();
    $settings->connected = true;
    $settings->accountName = 'Ada';
    $settings->accountEmail = 'ada@example.com';
    $settings->token = 'test-account-token';
    $settings->connectedAt = now()->toAtomString();
    $settings->save();
}

/** @return list<array<string, mixed>> */
function fakeCatalog(): array
{
    return [
        [
            'package' => 'acme/forum', 'name' => 'Acme Forum', 'shortDescription' => 'Forums',
            'version' => '1.3.0', 'compat' => '^1.0', 'icon' => 'https://x/i.png',
            'categories' => ['community'], 'author' => 'Acme', 'installs' => 10,
        ],
        // Incompatible with core 1.x.
        ['package' => 'acme/shop', 'name' => 'Acme Shop', 'version' => '2.0.0', 'compat' => '^2.0'],
        // Malformed — no package.
        ['name' => 'Broken', 'version' => '1.0.0', 'compat' => '^1.0'],
    ];
}

it('returns only compatible, well-formed listings', function (): void {
    Http::fake([Marketplace::API_BASE.'/plugins*' => Http::response(fakeCatalog())]);

    $plugins = app(MarketplaceClient::class)->plugins();

    expect($plugins)->toHaveCount(1)
        ->and($plugins[0])->toBeInstanceOf(PluginListing::class)
        ->and($plugins[0]->package)->toBe('acme/forum')
        ->and($plugins[0]->categories)->toBe(['community']);
});

it('keeps a listing icon only when it is an http(s) URL, as the cover already was', function (): void {
    $listing = static fn (mixed $icon): ?PluginListing => PluginListing::fromArray([
        'package' => 'acme/forum', 'name' => 'Acme Forum', 'version' => '1.0.0', 'compat' => '^1.0', 'icon' => $icon,
    ]);

    expect($listing('https://hub.test/storage/i.png')?->icon)->toBe('https://hub.test/storage/i.png')
        ->and($listing('javascript:alert(1)')?->icon)->toBeNull()
        ->and($listing('/relative/i.png')?->icon)->toBeNull()
        ->and($listing(null)?->icon)->toBeNull();
});

it('caches the catalog and does not refetch within the TTL', function (): void {
    Http::fake([Marketplace::API_BASE.'/plugins*' => Http::response(fakeCatalog())]);

    $client = app(MarketplaceClient::class);
    $client->plugins();
    $client->plugins();

    Http::assertSentCount(1);
});

it('returns an empty list (never throws) when the marketplace is unreachable', function (): void {
    Http::fake([Marketplace::API_BASE.'/*' => Http::response('', 503)]);

    expect(app(MarketplaceClient::class)->plugins())->toBe([]);
});

it('keeps serving the cached catalog when a later fetch fails', function (): void {
    Http::fake([Marketplace::API_BASE.'/plugins*' => Http::response(fakeCatalog())]);
    $client = app(MarketplaceClient::class);
    expect($client->plugins())->toHaveCount(1);

    // Marketplace goes down — the warm cache should still answer.
    Http::fake([Marketplace::API_BASE.'/*' => Http::response('', 503)]);
    expect($client->plugins())->toHaveCount(1);
});

it('finds a single plugin by package from the catalog', function (): void {
    Http::fake([Marketplace::API_BASE.'/plugins*' => Http::response(fakeCatalog())]);

    $plugin = app(MarketplaceClient::class)->plugin('acme/forum');

    expect($plugin?->name)->toBe('Acme Forum');
});

it('lists published versions for a package', function (): void {
    Http::fake([
        Marketplace::API_BASE.'/plugins/acme/forum/versions*' => Http::response([
            ['version' => '1.3.0'], ['version' => '1.2.0'],
        ]),
    ]);

    expect(app(MarketplaceClient::class)->versions('acme/forum'))->toBe(['1.3.0', '1.2.0']);
});

it('submits a review with the connected account bearer token', function (): void {
    connectAccountCentre();
    Http::fake([Marketplace::API_BASE.'/*' => Http::response(['ratingsCount' => 1])]);

    $ok = app(MarketplaceClient::class)->submitReview('acme/forum', 5, 'Great!', 'Ada');

    expect($ok)->toBeTrue();
    Http::assertSent(function ($request): bool {
        return str_ends_with($request->url(), '/plugins/acme/forum/ratings')
            && $request['stars'] === 5
            && $request['review'] === 'Great!'
            && $request['author'] === 'Ada'
            && $request->hasHeader('Authorization', 'Bearer test-account-token');
    });
});

it('reports a plugin to the marketplace with the connected account bearer token', function (): void {
    connectAccountCentre();
    Http::fake([Marketplace::API_BASE.'/*' => Http::response([], 201)]);

    $ok = app(MarketplaceClient::class)->reportPlugin('acme/forum', 'malicious', 'Backdoor');

    expect($ok)->toBeTrue();
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/plugins/acme/forum/reports')
        && $request['reason'] === 'malicious'
        && $request->hasHeader('Authorization', 'Bearer test-account-token'));
});

it('refuses to submit a review or report without a connected Magna Account', function (): void {
    Http::fake([Marketplace::API_BASE.'/*' => Http::response(['ratingsCount' => 1])]);

    expect(app(MarketplaceClient::class)->submitReview('acme/forum', 5))->toBeFalse()
        ->and(app(MarketplaceClient::class)->reportPlugin('acme/forum', 'spam'))->toBeFalse();

    Http::assertNothingSent();
});

it('returns false when submitting feedback fails, never throwing', function (): void {
    connectAccountCentre();
    Http::fake([Marketplace::API_BASE.'/*' => Http::response('', 500)]);

    expect(app(MarketplaceClient::class)->submitReview('acme/forum', 5))->toBeFalse()
        ->and(app(MarketplaceClient::class)->reportPlugin('acme/forum', 'spam'))->toBeFalse();
});

it('fetches the written reviews for a package', function (): void {
    Http::fake([Marketplace::API_BASE.'/plugins/acme/forum/reviews*' => Http::response([
        ['author' => 'Ada', 'stars' => 5, 'review' => 'Great!', 'date' => '2026-07-16T00:00:00+00:00'],
    ])]);

    $reviews = app(MarketplaceClient::class)->reviews('acme/forum');

    expect($reviews)->toHaveCount(1)->and($reviews[0]['author'])->toBe('Ada');
});
