<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Auth\Role;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Render a real admin page over HTTP, because nothing else did.
 *
 * The panel registers three render hooks — HEAD_END, BODY_END and FOOTER —
 * and each renders a Blade partial. Nothing in the suite rendered a panel
 * page through the HTTP stack, so a partial that could not be resolved
 * failed nowhere: the unit tests exercised the pages' Livewire classes, and
 * the hooks only fire when Filament composes the whole layout.
 *
 * 1.4.0 shipped those partials under resources/, which a core update does not
 * deliver. Every updated site then answered 500 on every admin page with
 * "View [filament.magna.footer] not found", and a fresh install of the same
 * release was fine — so the difference was invisible to anyone testing the
 * way releases were tested.
 */
function panelAdmin(): User
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

it('renders an admin page with every render hook resolved', function (): void {
    $response = $this->actingAs(panelAdmin())->get('/');

    $response->assertOk();

    // One assertion per hook, so a missing partial names itself.
    expect($response->getContent())
        // FOOTER
        ->toContain('Magna CMS')
        ->toContain('JR Studios')
        // HEAD_END — the cross-panel sidebar-state repair.
        ->toContain('magna_sb_session_v1')
        // BODY_END — the settings sub-nav smooth-scroll and scroll-spy.
        ->toContain('Filament overrides custom section ids');
});

it('renders the panel for a guest without falling over', function (): void {
    // The sign-in screen composes the same layout, so a broken partial takes
    // the login page with it — which is how an operator loses the way back in.
    $this->get('/')->assertRedirect();
});
