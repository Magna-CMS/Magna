<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Magna\Auth\Role;
use Magna\Auth\TwoFactorService;
use Magna\Users\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * The second factor is the last gate in front of the panel, and it used to be
 * the only auth endpoint with no rate limit, no attempt counter, and no
 * replay protection — a 6-digit code is brute-forceable in minutes without
 * them. These tests exist so that never silently regresses.
 */
function enrolledUser(): User
{
    $user = User::factory()->create([
        'password' => Hash::make('secret'),
        'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole(Role::factory()->create());

    return $user;
}

it('rate limits the challenge instead of allowing unlimited code guesses', function (): void {
    $user = enrolledUser();

    // The route throttle is 5/minute; the sixth attempt must be refused
    // outright rather than checked.
    for ($i = 0; $i < 5; $i++) {
        $this->withSession(['auth.two_factor_user_id' => $user->getKey()])
            ->post(route('auth.two-factor.challenge.verify'), ['code' => '000000'])
            ->assertStatus(302);
    }

    $this->withSession(['auth.two_factor_user_id' => $user->getKey()])
        ->post(route('auth.two-factor.challenge.verify'), ['code' => '000000'])
        ->assertStatus(429);

    $this->assertGuest();
});

it('refuses a TOTP code that has already been used', function (): void {
    $user = enrolledUser();
    $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);

    $this->withSession(['auth.two_factor_user_id' => $user->getKey()])
        ->post(route('auth.two-factor.challenge.verify'), ['code' => $code])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    // Same code, still inside its validity window, second session: an
    // observed code must not be replayable.
    auth()->logout();

    $this->withSession(['auth.two_factor_user_id' => $user->getKey()])
        ->post(route('auth.two-factor.challenge.verify'), ['code' => $code])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('stores the TOTP secret and recovery codes encrypted at rest', function (): void {
    $secret = app(TwoFactorService::class)->generateSecret();
    $codes = app(TwoFactorService::class)->generateRecoveryCodes(4);

    $user = User::factory()->create([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => $codes,
    ]);

    // Read the raw column, bypassing the cast: a database read must not hand
    // an attacker the ability to mint valid second factors.
    $raw = DB::table('users')->where('id', $user->getKey())->first();

    expect($raw->two_factor_secret)->not->toBe($secret)
        ->and($raw->two_factor_secret)->not->toContain($secret)
        ->and($raw->two_factor_recovery_codes)->not->toContain($codes[0]);

    // ...and still round-trips through the model.
    expect($user->fresh()?->two_factor_secret)->toBe($secret)
        ->and($user->fresh()?->two_factor_recovery_codes)->toBe($codes);
});
