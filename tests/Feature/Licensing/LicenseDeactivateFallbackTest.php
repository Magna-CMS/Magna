<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Auth\Role;
use Magna\Users\User;

/**
 * Releasing a seat must not depend on the token the seat was bought with.
 *
 * deactivate() spent the locally stored activation token, so a site whose
 * store had drifted — plugin uninstalled, key re-issued — answered "No licence
 * for that product is active on this site." while the marketplace showed this
 * very site holding the seat. Every exit was closed: Update wanted the same
 * dead token, Install here was hidden because the seat looked taken, and
 * Release refused. The account owns the licence, so the seat is now freed
 * through it when there is nothing local left to spend.
 */
beforeEach(function (): void {
    $settings = AccountCentreSettings::get();
    $settings->connected = true;
    $settings->token = 'site-account-token';
    $settings->save();
});

function deactivatingAdmin(): User
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

/** The wallet as the hub reports it for a site that holds a seat it cannot spend. */
function walletWithSeat(string $domain): array
{
    return ['licenses' => [[
        'id' => 6,
        'product_slug' => 'roya/dms',
        'licensable_type' => 'plugin',
        'license_type' => 'corporate',
        'status' => 'active',
        'active_on_this_site' => true,
        'activations' => [
            ['id' => 'act-9', 'site_domain' => $domain, 'is_dev' => false, 'activated_at' => now()->toIso8601String()],
        ],
    ]]];
}

it('releases the seat through the account when no local token exists', function (): void {
    $this->actingAs(deactivatingAdmin());

    $host = parse_url((string) config('app.url'), PHP_URL_HOST);

    Http::fake([
        '*/account/licenses/6/deactivate-site' => Http::response(['ok' => true]),
        '*/account/licenses' => Http::response(walletWithSeat($host)),
        '*' => Http::response(null, 404),
    ]);

    $response = $this->post(route('licensing.deactivate'), ['product_slug' => 'roya/dms']);

    $response->assertRedirect();
    $response->assertSessionHas('account_centre_status', fn (?string $message): bool => str_contains((string) $message, 'released through your Magna Account'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/account/licenses/6/deactivate-site')
        && $request['activation_id'] === 'act-9');
});

it('still refuses when the account holds no seat for this site', function (): void {
    $this->actingAs(deactivatingAdmin());

    Http::fake([
        '*/account/licenses' => Http::response(['licenses' => []]),
        '*' => Http::response(null, 404),
    ]);

    $this->post(route('licensing.deactivate'), ['product_slug' => 'roya/dms'])
        ->assertSessionHas('account_centre_error', 'No licence for that product is active on this site.');
});

// The activation is matched by this site's own domain — another site's seat on
// the same licence must never be the one released.
it('never releases an activation that belongs to a different domain', function (): void {
    $this->actingAs(deactivatingAdmin());

    Http::fake([
        '*/account/licenses' => Http::response(walletWithSeat('someone-else.example')),
        '*' => Http::response(null, 404),
    ]);

    $this->post(route('licensing.deactivate'), ['product_slug' => 'roya/dms'])
        ->assertSessionHas('account_centre_error');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'deactivate-site'));
});
