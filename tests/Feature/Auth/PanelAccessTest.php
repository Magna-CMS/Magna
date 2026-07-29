<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Filament\Panel;
use Magna\Auth\Role;
use Magna\Users\User;
use Magna\Users\UserStatus;

/**
 * Who may open the admin panel.
 *
 * This used to be "active and holds any role", which meant a plugin seeding
 * roles for its own users handed every one of them the CMS back end. The door
 * has its own permission now, and these are the cases that matter.
 */
function panel(): Panel
{
    $panels = Filament::getPanels();

    return reset($panels);
}

it('admits a role that carries panel access', function (): void {
    $role = Role::query()->create(['handle' => 'ops', 'name' => 'Operations']);
    $role->grant('panel.access');

    $user = User::factory()->create(['status' => UserStatus::Active]);
    $user->roles()->attach($role);

    expect($user->canAccessPanel(panel()))->toBeTrue();
});

it('refuses a plugin role that has its own permissions but not the door', function (): void {
    // The exact shape that caused this: a plugin's administrator, fully
    // privileged inside its own product, with no business in the CMS.
    $role = Role::query()->create(['handle' => 'portal-admin', 'name' => 'Portal Admin']);
    $role->grant('erp.companies.manage', 'dms.documents.upload');

    $user = User::factory()->create(['status' => UserStatus::Active]);
    $user->roles()->attach($role);

    expect($user->canAccessPanel(panel()))->toBeFalse();
});

it('refuses a suspended account that would otherwise be admitted', function (): void {
    $role = Role::query()->create(['handle' => 'ops', 'name' => 'Operations']);
    $role->grant('panel.access');

    $user = User::factory()->create(['status' => UserStatus::Suspended]);
    $user->roles()->attach($role);

    expect($user->canAccessPanel(panel()))->toBeFalse();
});

it('refuses an account with no roles at all', function (): void {
    $user = User::factory()->create(['status' => UserStatus::Active]);

    expect($user->canAccessPanel(panel()))->toBeFalse();
});

it('still admits a super admin, who bypasses every check', function (): void {
    $role = Role::query()->create([
        'handle' => 'root',
        'name' => 'Root',
        'is_super_admin' => true,
    ]);

    $user = User::factory()->create(['status' => UserStatus::Active]);
    $user->roles()->attach($role);

    expect($user->canAccessPanel(panel()))->toBeTrue();
});

it('gives the seeded operator roles the door', function (): void {
    $this->seed(RoleSeeder::class);

    foreach (['admin', 'editor', 'viewer'] as $handle) {
        $role = Role::query()->where('handle', $handle)->firstOrFail();

        expect($role->grants())->toContain('panel.access');
    }
});
