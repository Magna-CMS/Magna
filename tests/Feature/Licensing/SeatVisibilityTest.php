<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Admin\Pages\AccountCentrePage;
use Magna\Auth\Role;
use Magna\Licensing\SeatSummary;
use Magna\Users\User;

beforeEach(function (): void {
    $settings = AccountCentreSettings::get();
    $settings->connected = true;
    $settings->token = 'site-account-token';
    $settings->save();
});

/**
 * The wallet has carried activation_limit and activations[] since seats
 * existed, and the account page rendered neither — so a licence with no room
 * left looked exactly like one with room, and the first anyone heard of a
 * limit was an install that refused.
 */
function seatWallet(array $overrides = []): array
{
    return array_merge([
        'id' => 7,
        'product_slug' => 'magna/blog',
        'licensable_type' => 'plugin',
        'license_type' => 'lifetime',
        'status' => 'active',
        'key' => 'MAGNA-AAAA-BBBB-CCCC-DDDD-EEEE-FFFF',
        'license_expires_at' => null,
        'activation_limit' => 2,
        'active_on_this_site' => true,
        'activations' => [
            ['id' => 'act-live', 'site_domain' => 'example.com', 'is_dev' => false, 'activated_at' => '2026-09-01T00:00:00+00:00'],
            ['id' => 'act-dev', 'site_domain' => 'magna-cms.test', 'is_dev' => true, 'activated_at' => '2026-09-02T00:00:00+00:00'],
        ],
        'subscription' => null,
    ], $overrides);
}

function seatAdmin(): User
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

it('counts one seat per domain, not one per activation row', function (): void {
    // A site rebuilt under a new APP_KEY reports a fresh fingerprint, so the
    // wallet can hold two rows for one website. Telling the owner that is two
    // seats would be a lie the licence server does not tell either.
    $decorated = SeatSummary::decorate([seatWallet(['activations' => [
        ['id' => 'act-old', 'site_domain' => 'example.com', 'is_dev' => false],
        ['id' => 'act-new', 'site_domain' => 'example.com', 'is_dev' => false],
        ['id' => 'act-dev', 'site_domain' => 'magna-cms.test', 'is_dev' => true],
    ]])]);

    expect($decorated[0]['seats_used'])->toBe(2)
        ->and(array_column($decorated[0]['seats'], 'domain'))->toBe(['example.com', 'magna-cms.test']);
});

it('marks the seat this site is holding', function (): void {
    config(['app.url' => 'https://example.com']);

    $seats = SeatSummary::decorate([seatWallet()])[0]['seats'];

    expect(collect($seats)->firstWhere('domain', 'example.com')['is_this_site'])->toBeTrue()
        ->and(collect($seats)->firstWhere('domain', 'magna-cms.test')['is_this_site'])->toBeFalse();
});

it('drops activation rows the server did not identify', function (): void {
    $decorated = SeatSummary::decorate([seatWallet(['activations' => [
        ['id' => 'act-ok', 'site_domain' => 'example.com', 'is_dev' => false],
        ['site_domain' => 'no-id.com'],
        ['id' => 'act-nodomain'],
        'not an array',
    ]])]);

    expect($decorated[0]['seats_used'])->toBe(1);
});

it('shows the seat count and the domains holding them', function (): void {
    config(['app.url' => 'https://example.com']);

    Http::fake([
        '*/account/licenses' => Http::response(['licenses' => [seatWallet()]]),
        '*' => Http::response([], 200),
    ]);

    $this->actingAs(seatAdmin());

    Livewire::test(AccountCentrePage::class)
        ->assertSee('2 of 2')
        ->assertSee('example.com')
        ->assertSee('magna-cms.test')
        ->assertSee('Buy another licence')
        ->assertSee('this site');
});

it('says nothing about releasing when the licence still has room', function (): void {
    Http::fake([
        '*/account/licenses' => Http::response(['licenses' => [seatWallet(['activation_limit' => 5])]]),
        '*' => Http::response([], 200),
    ]);

    $this->actingAs(seatAdmin());

    Livewire::test(AccountCentrePage::class)
        ->assertSee('2 of 5')
        ->assertDontSee('Buy another licence');
});

it('offers no release action anywhere on the page', function (): void {
    // Seats are managed on the marketplace, never from a site's admin — a
    // release action here let one signed-in admin unlicense every other
    // domain on the key. The routes are gone with the buttons.
    config(['app.url' => 'https://example.com']);

    Http::fake([
        '*/account/licenses' => Http::response(['licenses' => [seatWallet()]]),
        '*' => Http::response([], 200),
    ]);

    $this->actingAs(seatAdmin());

    Livewire::test(AccountCentrePage::class)
        ->assertSee('example.com')
        ->assertDontSee('Release');

    expect(Route::has('licensing.release-site'))->toBeFalse()
        ->and(Route::has('licensing.deactivate'))->toBeFalse();
});

it('lists plugin and theme licences as separate sections', function (): void {
    // One mixed table made a theme licence read as a plugin nobody could
    // find in the Plugins list.
    Http::fake([
        '*/account/licenses' => Http::response(['licenses' => [
            seatWallet(),
            seatWallet(['id' => 8, 'product_slug' => 'lekha/billing', 'licensable_type' => 'theme', 'key' => 'MAGNA-1111-2222-3333-4444-5555-6666']),
        ]]),
        '*' => Http::response([], 200),
    ]);

    $this->actingAs(seatAdmin());

    Livewire::test(AccountCentrePage::class)
        ->assertSeeInOrder(['Plugins', 'magna/blog', 'Themes', 'lekha/billing']);
});

it('points a full licence at the product it is sold on', function (): void {
    // Adding a site and moving one are different problems; the storefront
    // link is the answer to the commoner one.
    $decorated = SeatSummary::decorate([seatWallet()]);

    expect($decorated[0]['store_url'])->toBe('https://managemagna.jrstudios.dev/magna/blog');
});

it('offers no product link for a slug it cannot place', function (): void {
    $decorated = SeatSummary::decorate([seatWallet(['product_slug' => 'not-a-package'])]);

    expect($decorated[0]['store_url'])->toBeNull();
});
