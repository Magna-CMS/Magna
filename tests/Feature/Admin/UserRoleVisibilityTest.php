<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\Admin\Resources\User\ListUsers;
use Magna\Auth\Role;
use Magna\Users\User;
use Tests\TestCase;

/**
 * Accounts a plugin created must be legible in the CMS panel.
 *
 * Plugins seed their own roles into the core role table and assign them to core
 * users — Roya's portal accounts, EMBHAS's stakeholders. The user list showed
 * those accounts all along but said nothing about what any of them *are*, so a
 * portal super admin and a brand-new signup looked identical. The roles are the
 * answer to "who is this", which makes them part of the list, not a detail
 * behind an edit screen.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

function usersAdmin(): User
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

it('shows a plugin-seeded role next to the account holding it', function (): void {
    $this->actingAs(usersAdmin());

    Role::query()->create(['handle' => 'roya-super-admin', 'name' => 'Roya Super Admin']);
    $portalUser = User::factory()->create(['name' => 'Priya Nair']);
    $portalUser->assignRole('roya-super-admin');

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$portalUser])
        ->assertSee('Priya Nair')
        ->assertSee('Roya Super Admin');
});

it('filters the list down to one role', function (): void {
    $this->actingAs(usersAdmin());

    Role::query()->create(['handle' => 'roya-user', 'name' => 'Roya User']);
    Role::query()->create(['handle' => 'embhas-officer', 'name' => 'EMBHAS Officer']);

    $portalUser = User::factory()->create(['name' => 'Portal Person']);
    $portalUser->assignRole('roya-user');

    $officer = User::factory()->create(['name' => 'Field Officer']);
    $officer->assignRole('embhas-officer');

    $roleId = Role::query()->where('handle', 'roya-user')->value('id');

    Livewire::test(ListUsers::class)
        ->filterTable('roles', [$roleId])
        ->assertCanSeeTableRecords([$portalUser])
        ->assertCanNotSeeTableRecords([$officer]);
});

// Nothing about the list may hide an account: an operator who cannot see a
// plugin's users cannot suspend one either.
it('lists an account that holds no role at all', function (): void {
    $this->actingAs(usersAdmin());

    $orphan = User::factory()->create(['name' => 'No Role Yet']);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$orphan]);
});
