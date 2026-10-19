<?php

declare(strict_types=1);

/**
 * What a failed admin request tells the operator.
 *
 * In production every failed Livewire request showed Filament's one generic
 * "Error while loading page" toast — an expired session, maintenance during
 * an update and a genuine 500 were indistinguishable. The panel now registers
 * words for the two an operator can act on (419, 503), and a BODY_END script
 * reloads an expired session to the login screen on its own.
 */

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Auth\Role;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function errorExperienceAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('registers words for the two failures an operator can act on', function (): void {
    $notifications = Filament::getPanel('magna')->getErrorNotifications();

    expect($notifications[419]['title'] ?? null)->toBe('Your session expired')
        ->and($notifications[503]['title'] ?? null)->toBe('Updating — back in a moment')
        // The vendor fallback for everything else stays.
        ->and($notifications['']['title'] ?? null)->not->toBeNull();
});

it('ships the session reload script on every panel page', function (): void {
    $this->actingAs(errorExperienceAdmin());

    $this->get('/')->assertOk()->assertSee('data-magna-session-reload', false);
});
