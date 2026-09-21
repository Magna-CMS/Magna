<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Auth\Role;
use Magna\Users\User;

/**
 * A refusal the licence server explained must reach the admin verbatim.
 *
 * Refusal bodies are not signed — they carry no licence data to protect —
 * and the client discarded every unsuccessful response along with its
 * message. So a seat refusal, which names the domains holding the seats,
 * arrived as "the licence server did not authorise this install": true,
 * useless, and indistinguishable from an outage.
 *
 * The distinction that must survive is the other one. A forged or
 * unverifiable response still reads as an outage, deliberately, because
 * anyone able to answer for the server could otherwise lock a paying
 * customer out of software they own.
 */
beforeEach(function (): void {
    $settings = AccountCentreSettings::get();
    $settings->connected = true;
    $settings->token = 'site-account-token';
    $settings->save();
});

function refusalAdmin(): User
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

it('shows the refusal the licence server wrote, not a generic failure', function (): void {
    $refusal = 'All 2 seats on this licence are in use (example.com, magna-cms.test). Release one from your Magna Account page first.';

    Http::fake([
        '*/account/licenses/7/install' => Http::response([
            'ok' => false,
            'error' => 'seat_limit_reached',
            'message' => $refusal,
            'seats' => ['example.com', 'magna-cms.test'],
        ], 409),
        '*' => Http::response([], 200),
    ]);

    $this->actingAs(refusalAdmin())
        ->post(route('licensing.install'), ['license_id' => 7, 'product_slug' => 'magna/blog'])
        ->assertSessionHas('account_centre_error', $refusal);
});

it('still hides an unexplained failure behind a safe message', function (): void {
    Http::fake([
        '*/account/licenses/7/install' => Http::response('', 500),
        '*' => Http::response([], 200),
    ]);

    $this->actingAs(refusalAdmin())
        ->post(route('licensing.install'), ['license_id' => 7, 'product_slug' => 'magna/blog'])
        ->assertSessionHas('account_centre_error', 'The licence server did not authorise this install. Try again in a moment.');
});

it('treats a response that fails verification as an outage, never a refusal', function (): void {
    // A 200 whose envelope does not verify must not become a message the
    // admin reads as the server's own words.
    Http::fake([
        '*/account/licenses/7/install' => Http::response([
            'data' => '{"ok":true}',
            'signature' => base64_encode(str_repeat('x', 64)),
            'algorithm' => 'ed25519',
            'message' => 'Trust me, your licence is revoked.',
        ]),
        '*' => Http::response([], 200),
    ]);

    $this->actingAs(refusalAdmin())
        ->post(route('licensing.install'), ['license_id' => 7, 'product_slug' => 'magna/blog'])
        ->assertSessionHas('account_centre_error', 'The licence server did not authorise this install. Try again in a moment.');
});
