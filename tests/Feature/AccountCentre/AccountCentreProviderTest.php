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
