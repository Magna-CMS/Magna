<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Magna\Auth\Filament\Login;
use Magna\Auth\LoginThrottle;
use Magna\Auth\Role;
use Magna\Users\User;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

/** Drive the single Filament sign-in page. */
function attemptLogin(string $email, string $password)
{
    return Livewire::test(Login::class)
        ->fillForm(['email' => $email, 'password' => $password])
        ->call('authenticate');
}

it('logs in with correct credentials', function (): void {
    $user = User::factory()->create(['password' => Hash::make('secret')]);
    $user->assignRole(Role::factory()->withPanelAccess()->create());

    attemptLogin($user->email, 'secret')->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

it('rejects wrong password', function (): void {
    $user = User::factory()->create(['password' => Hash::make('secret')]);
    $user->assignRole(Role::factory()->withPanelAccess()->create());

    attemptLogin($user->email, 'wrong')->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('rejects suspended accounts', function (): void {
    $user = User::factory()->suspended()->create(['password' => Hash::make('secret')]);
    $user->assignRole(Role::factory()->withPanelAccess()->create());

    attemptLogin($user->email, 'secret')->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('redirects the legacy login route to the single Filament sign-in page', function (): void {
    $this->get(route('auth.login'))->assertRedirect(route('filament.magna.auth.login'));
});

it('logs out and clears session', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('auth.logout'))
        ->assertRedirect(route('filament.magna.auth.login'));

    $this->assertGuest();
});

it('brute-force lockout kicks in after max_attempts consecutive failures', function (): void {
    Cache::flush();
    config(['magna.login.max_attempts' => 3, 'magna.login.base_lockout_seconds' => 30]);

    $user = User::factory()->create(['password' => Hash::make('secret')]);
    $user->assignRole(Role::factory()->withPanelAccess()->create());

    // 3 failures reach the lockout threshold.
    attemptLogin($user->email, 'wrong');
    attemptLogin($user->email, 'wrong');
    attemptLogin($user->email, 'wrong');

    expect(app(LoginThrottle::class)->isLocked($user->email))->toBeTrue();

    // A further attempt is refused with the lockout message.
    attemptLogin($user->email, 'wrong')->assertHasFormErrors(['email']);
});

it('returns 404 for registration when disabled', function (): void {
    // GeneralSettings::registration_enabled defaults to false — no DB entry needed.
    Cache::flush();

    $this->post(route('auth.register.store'), [
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});
