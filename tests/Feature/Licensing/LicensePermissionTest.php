<?php

declare(strict_types=1);

use Magna\Auth\Role;
use Magna\Users\User;

/**
 * Licence actions download a package and enable it — that is third-party code
 * executing on this server on the next request. They were previously gated on
 * `settings.view`, a read-only permission, so any support/auditor role could
 * reach them.
 */
function userWithPermissions(array $permissions): User
{
    $role = Role::factory()->create();
    $role->grant(...$permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('refuses licence installs to a read-only settings role', function (): void {
    $user = userWithPermissions(['settings.view']);

    $this->actingAs($user)
        ->post(route('licensing.install'), ['license_id' => 1, 'product_slug' => 'acme/crm'])
        ->assertForbidden();
});

it('refuses licence deactivation to a settings administrator without licensing.manage', function (): void {
    $user = userWithPermissions(['settings.view', 'settings.manage']);

    $this->actingAs($user)
        ->post(route('licensing.deactivate'), ['product_slug' => 'acme/crm'])
        ->assertForbidden();
});

it('allows a holder of licensing.manage through the gate', function (): void {
    $user = userWithPermissions(['licensing.manage']);

    // Past authorisation: no licence for that slug is stored, so the action
    // reports that rather than a 403.
    $this->actingAs($user)
        ->post(route('licensing.deactivate'), ['product_slug' => 'acme/crm'])
        ->assertRedirect()
        ->assertSessionHas('account_centre_error');
});
