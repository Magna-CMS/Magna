<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Magna\Audit\AuditLog;
use Magna\Auth\Filament\Login;
use Magna\Auth\Role;
use Magna\Users\User;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

it('sends an enrolled user to the 2FA challenge instead of logging them straight in', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('secret'),
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole(Role::factory()->create());

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'secret'])
        ->call('authenticate')
        ->assertRedirect(route('auth.two-factor.challenge'));

    // Still not authenticated — only pending the challenge.
    $this->assertGuest();
    expect(session('auth.two_factor_user_id'))->toBe($user->getKey());

    // Audit integrity: no "login success" is recorded until the second factor
    // is actually verified (the Login event must not fire during the divert).
    expect(AuditLog::query()->where('action', 'auth.login.success')->count())->toBe(0);
});

it('logs a user without 2FA straight into the panel', function (): void {
    $user = User::factory()->create(['password' => Hash::make('secret')]);
    $user->assignRole(Role::factory()->create());

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'secret'])
        ->call('authenticate');

    $this->assertAuthenticatedAs($user);
    expect(session('auth.two_factor_user_id'))->toBeNull();
});
