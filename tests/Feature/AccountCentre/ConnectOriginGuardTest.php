<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Auth\Role;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The handshake may only start on the address this install calls its own.
 *
 * Found on a live installation. The panel was opened at the server's LAN IP
 * and the Magna Account connected from there, which registered
 * `http://192.168.1.8` as the site holding the licence seats. The marketplace
 * stamps an activation's domain from that value and keys the activation row by
 * fingerprint, so the install rebound its OWN seat to the new address and
 * rotated the activation token — leaving the running plugin presenting a token
 * that was no longer any row's hash, and every verify answering
 *
 *   Activation token invalid or revoked. (invalid_token)
 *
 * from an account that was connected and a licence that was paid for.
 *
 * It takes no misconfiguration to reach: TrustHosts trusts APP_URL's host and
 * its subdomains, so apex and `www` both answer with nothing set, as does any
 * address on MAGNA_TRUSTED_HOSTS. The handshake has to keep running on the
 * browsing origin (the state nonce lives in that origin's session, and the
 * marketplace's open-redirect guard refuses a callback that is not same-origin
 * with site_url), so the only safe answer is to refuse to start anywhere else
 * and name the address that will work.
 */
function connectGuardAdmin(): User
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

it('starts the handshake when the panel is open at this install’s own address', function (): void {
    config(['app.url' => 'https://example.test']);
    $this->actingAs(connectGuardAdmin());

    $response = $this->get('https://example.test/account-centre/connect/github');

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain('/account/connect/github')
        ->toContain(urlencode('https://example.test'));
});

it('refuses to start the handshake from a second trusted host', function (): void {
    config([
        'app.url' => 'https://example.test',
        'magna.security.trusted_hosts' => '192.168.1.8',
    ]);
    $this->actingAs(connectGuardAdmin());

    $response = $this->get('http://192.168.1.8/account-centre/connect/github');

    // Nowhere near the marketplace: the seat must not be stamped from here.
    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toContain('/account/connect/');

    // Both addresses, because one of them is the one to go and use.
    $error = session('account_centre_error');
    expect($error)
        ->toContain('http://192.168.1.8')
        ->toContain('https://example.test');
});

it('treats a scheme difference on the same host as a different site', function (): void {
    // The account stores the origin, not the hostname — http and https on one
    // name are two sites to it, and two seats on a licence that carries one.
    config(['app.url' => 'https://example.test']);
    $this->actingAs(connectGuardAdmin());

    $response = $this->get('http://example.test/account-centre/connect/github');

    expect($response->headers->get('Location'))->not->toContain('/account/connect/');
});

it('ignores a port the scheme already implies', function (): void {
    // getSchemeAndHttpHost() omits 443 on https, so an APP_URL written with it
    // must not read as a different origin from the request that matches it.
    config(['app.url' => 'https://example.test:443']);
    $this->actingAs(connectGuardAdmin());

    $response = $this->get('https://example.test/account-centre/connect/github');

    expect($response->headers->get('Location'))->toContain('/account/connect/github');
});

it('holds no opinion when the install cannot say where it lives', function (): void {
    // Mid-installation APP_URL is absent or malformed. Refusing every connect
    // on a site that has not been told its own address would be a worse
    // failure than the one this guard prevents.
    config(['app.url' => '']);
    $this->actingAs(connectGuardAdmin());

    $response = $this->get('http://192.168.1.8/account-centre/connect/github');

    expect($response->headers->get('Location'))->toContain('/account/connect/github');
});
