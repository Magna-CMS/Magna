<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Auth\Role;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function accountCentreSuperAdmin(): User
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

// connect()'s {provider} route param used to flow unvalidated into an
// external redirect (Marketplace::WEB_BASE."/account/connect/{$provider}").
// The fixed host bounds the impact, but an arbitrary path segment on that
// host reaching the browser via a Location header from our own app is still
// worth allowlisting rather than trusting the route param.
it('redirects to the connect flow for an allowlisted provider', function (): void {
    $this->actingAs(accountCentreSuperAdmin());

    $response = $this->get(route('account-centre.connect', 'github'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/account/connect/github');
});

it('redirects to the connect flow for Microsoft', function (): void {
    $this->actingAs(accountCentreSuperAdmin());

    $response = $this->get(route('account-centre.connect', 'microsoft'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/account/connect/microsoft');
});

it('rejects a provider that is not on the allowlist', function (): void {
    $this->actingAs(accountCentreSuperAdmin());

    $this->get(route('account-centre.connect', 'not-a-real-provider'))
        ->assertNotFound();
});

it('requires settings.manage to start a connect attempt', function (): void {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->actingAs($user);

    $this->get(route('account-centre.connect', 'github'))
        ->assertForbidden();
});

// The connect handshake tells Update Manager two things — which site is
// asking (site_url) and where to send the browser back (callback) — and it
// refuses the pair unless both name the same origin, because otherwise
// `callback` could point anywhere and it would ship an exchange code there.
// site_url used to be read from APP_URL while the callback was built by
// url(), which follows the browser's host. On a panel reached at a second
// address — a LAN IP, a staging alias — the two disagreed, the guard
// refused, and the admin was dropped on the Magna Account sign-in page with
// nothing explaining why.
it('conducts the handshake on the origin the administrator is browsing', function (): void {
    config(['app.url' => 'https://erp.example.com']);
    $this->actingAs(accountCentreSuperAdmin());

    // The panel opened on the LAN while APP_URL is the public hostname —
    // exactly the deployment that used to send an unsatisfiable pair.
    $response = $this->get('http://192.168.1.8/account-centre/connect/github');

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    // Not APP_URL: this is the origin that can actually be returned to, and
    // the only one whose session holds the state nonce that has to come back.
    expect($query['site_url'])->toBe('http://192.168.1.8')
        ->and($query['callback'])->toBe('http://192.168.1.8/account-centre/callback');
});

it('sends a callback that is same-origin with the site_url it claims', function (): void {
    config(['app.url' => 'https://cms.example.com']);
    $this->actingAs(accountCentreSuperAdmin());

    $response = $this->get('https://cms.example.com/account-centre/connect/google');

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $originOf = static fn (string $url): string => (string) parse_url($url, PHP_URL_SCHEME)
        .'://'.(string) parse_url($url, PHP_URL_HOST);

    expect($originOf($query['callback']))->toBe($originOf($query['site_url']));
});

// Update Manager names the reason a connect failed. Reporting them all as
// "the connection attempt failed" sent people round the same loop — retrying
// helps for one of these and is useless for the rest.
//
// The messages are read by a customer administering their own site, so each
// one says only what that person can act on: no internal names, no pointing
// at a server log they have no access to, no apportioning blame between the
// two sides.
it('says what the administrator can do about a reason Update Manager names', function (string $code, string $expected): void {
    $this->actingAs(accountCentreSuperAdmin());

    $this->get('/account-centre/callback?error='.$code)
        ->assertSessionHas('account_centre_error', fn (string $message): bool => str_contains($message, $expected));
})->with([
    'provider is not configured' => ['provider_unavailable', 'not available at the moment'],
    'the provider withheld an email' => ['no_email', 'did not share an email'],
    'the email belongs to another sign-in method' => ['email_in_use', 'already exists'],
    'the sign-in was abandoned or failed on the far side' => ['oauth_failed', 'did not complete'],
    'something unrecognised' => ['a_code_from_a_newer_release', 'did not complete'],
]);

it('keeps our internals out of what the administrator is shown', function (string $code): void {
    $this->actingAs(accountCentreSuperAdmin());

    $this->get('/account-centre/callback?error='.$code)
        ->assertSessionHas('account_centre_error', function (string $message): bool {
            // A customer cannot read our logs and should not be told whose
            // fault it was. Both belong in Update Manager's own log instead.
            foreach (['log', 'server', 'exception', "Update Manager's side"] as $leak) {
                if (str_contains(strtolower($message), strtolower($leak))) {
                    return false;
                }
            }

            return true;
        });
})->with(['provider_unavailable', 'no_email', 'email_in_use', 'oauth_failed', 'something_new']);
