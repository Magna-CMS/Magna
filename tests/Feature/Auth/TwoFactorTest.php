<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Magna\Auth\Filament\Login;
use Magna\Auth\Http\Middleware\EnsureTwoFactorEnrolled;
use Magna\Auth\Role;
use Magna\Auth\TwoFactorService;
use Magna\Users\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * Exercises EnsureTwoFactorEnrolled::handle() directly against a fake
 * request, rather than a full HTTP round-trip through the Filament panel —
 * hitting the panel root ('/') in a plain test HTTP client 403s regardless
 * of this middleware (confirmed by isolating the panel middleware stack),
 * a pre-existing Filament test-bootstrapping quirk unrelated to this fix.
 * This still exercises the exact same middleware class wired into both
 * AdminPanelProvider and the Auth module's 'auth' web route group.
 */
function twoFactorEnrollmentGateResult(User $user, string $routeName = 'some.other.route'): string
{
    Auth::login($user);

    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => new class($routeName)
    {
        public function __construct(private string $name) {}

        public function named(string ...$patterns): bool
        {
            return in_array($this->name, $patterns, true);
        }
    });

    $response = (new EnsureTwoFactorEnrolled)->handle($request, fn () => response('ok'));

    if ($response->isRedirect()) {
        return 'redirected:'.$response->headers->get('Location');
    }

    return 'passed-through';
}

it('enrols 2FA and returns a secret + QR code SVG', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('auth.two-factor.enrol'));

    $response->assertOk()
        ->assertJsonStructure(['secret', 'qr_code']);

    expect($user->fresh()?->two_factor_secret)->not->toBeNull();
    expect($user->fresh()?->two_factor_confirmed_at)->toBeNull();
});

it('confirms enrollment with a valid TOTP code', function (): void {
    $twoFactor = app(TwoFactorService::class);
    $user = User::factory()->create(['two_factor_secret' => $twoFactor->generateSecret()]);
    $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);

    $response = $this->actingAs($user)
        ->postJson(route('auth.two-factor.confirm'), ['code' => $code]);

    $response->assertOk()
        ->assertJsonStructure(['recovery_codes']);

    expect($user->fresh()?->two_factor_confirmed_at)->not->toBeNull();
    expect($response->json('recovery_codes'))->toHaveCount(8);
});

it('rejects confirmation with a wrong code', function (): void {
    $twoFactor = app(TwoFactorService::class);
    $user = User::factory()->create(['two_factor_secret' => $twoFactor->generateSecret()]);

    $this->actingAs($user)
        ->postJson(route('auth.two-factor.confirm'), ['code' => '000000'])
        ->assertStatus(422);
});

it('disables 2FA with correct password', function (): void {
    $twoFactor = app(TwoFactorService::class);
    $user = User::factory()->create([
        'password' => Hash::make('secret'),
        'two_factor_secret' => $twoFactor->generateSecret(),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->deleteJson(route('auth.two-factor.disable'), ['password' => 'secret'])
        ->assertOk();

    expect($user->fresh()?->two_factor_confirmed_at)->toBeNull()
        ->and($user->fresh()?->two_factor_secret)->toBeNull();
});

// S1-12: disabling 2FA must revoke existing API tokens, matching the same
// defensive pattern password reset already uses — otherwise a management
// token minted while 2FA was enrolled stays valid after 2FA is turned off.
it('revokes all API tokens when 2FA is disabled', function (): void {
    $twoFactor = app(TwoFactorService::class);
    $user = User::factory()->create([
        'password' => Hash::make('secret'),
        'two_factor_secret' => $twoFactor->generateSecret(),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->createToken('mgmt', ['management'], now()->addDay());
    expect($user->tokens()->count())->toBe(1);

    $this->actingAs($user)
        ->deleteJson(route('auth.two-factor.disable'), ['password' => 'secret'])
        ->assertOk();

    expect($user->fresh()?->tokens()->count())->toBe(0);
});

it('rejects 2FA disable with wrong password', function (): void {
    $twoFactor = app(TwoFactorService::class);
    $user = User::factory()->create([
        'password' => Hash::make('secret'),
        'two_factor_secret' => $twoFactor->generateSecret(),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->deleteJson(route('auth.two-factor.disable'), ['password' => 'wrong'])
        ->assertStatus(422);
});

it('completes login via TOTP challenge', function (): void {
    $twoFactor = app(TwoFactorService::class);
    $secret = $twoFactor->generateSecret();
    $role = Role::factory()->create(['requires_two_factor' => true]);
    $user = User::factory()->create([
        'password' => Hash::make('secret'),
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    // Simulate pending challenge state set by LoginController
    $this->withSession(['auth.two_factor_user_id' => $user->getKey()]);

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('auth.two-factor.challenge.verify'), ['code' => $code])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('rejects an invalid code at the challenge', function (): void {
    $twoFactor = app(TwoFactorService::class);
    $user = User::factory()->create([
        'two_factor_secret' => $twoFactor->generateSecret(),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->withSession(['auth.two_factor_user_id' => $user->getKey()])
        ->post(route('auth.two-factor.challenge.verify'), ['code' => '000000'])
        ->assertRedirect()
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('completes challenge with a recovery code and removes it', function (): void {
    $twoFactor = app(TwoFactorService::class);
    $codes = $twoFactor->generateRecoveryCodes(8);
    $user = User::factory()->create([
        'two_factor_secret' => $twoFactor->generateSecret(),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => $codes,
    ]);

    $this->withSession(['auth.two_factor_user_id' => $user->getKey()])
        ->post(route('auth.two-factor.challenge.verify'), ['recovery_code' => $codes[0]])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    /** @var list<string> $remaining */
    $remaining = $user->fresh()?->two_factor_recovery_codes ?? [];
    expect($remaining)->toHaveCount(7)
        ->and($remaining)->not->toContain($codes[0]);
});

it('role-required 2FA blocks login until challenge is passed', function (): void {
    $role = Role::factory()->create(['requires_two_factor' => true]);
    $user = User::factory()->create([
        'password' => Hash::make('secret'),
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    // Login attempt → redirect to challenge (not to dashboard)
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'secret'])
        ->call('authenticate')
        ->assertRedirect(route('auth.two-factor.challenge'));

    $this->assertGuest();
});

// ── S1-06: 2FA enrollment must actually be forced, not just optional ─────────

it('forces an un-enrolled user with a 2FA-required role to the setup page on every authenticated request', function (): void {
    $role = Role::factory()->create(['requires_two_factor' => true]);
    $user = User::factory()->create([
        'password' => Hash::make('secret'),
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);
    $user->assignRole($role);

    // A user who has NOT enrolled is logged in normally (no challenge); the
    // enrollment gate below is what then forces them to set 2FA up.
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'secret'])
        ->call('authenticate');

    $this->assertAuthenticatedAs($user);

    // Any subsequent authenticated request must be redirected to the
    // mandatory setup page, not reach the requested resource — verified
    // directly against the middleware wired into both the admin panel and
    // the Auth module's 'auth' web route group (see twoFactorEnrollmentGateResult()).
    expect(twoFactorEnrollmentGateResult($user->fresh()))
        ->toBe('redirected:'.route('auth.two-factor.setup'));
});

it('lets an un-enrolled user with a required role reach and complete the setup page', function (): void {
    $role = Role::factory()->create(['requires_two_factor' => true]);
    $user = User::factory()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
    $user->assignRole($role);

    $this->actingAs($user);

    // The setup page itself must be reachable without being redirected back to itself.
    $this->get(route('auth.two-factor.setup'))->assertOk();

    $secret = $user->fresh()?->two_factor_secret;
    expect($secret)->not->toBeNull();

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('auth.two-factor.setup.store'), ['code' => $code])
        ->assertOk();

    $user->refresh();
    expect($user->two_factor_confirmed_at)->not->toBeNull();

    // Now enrolled — the enforcement middleware must let normal requests through.
    expect(twoFactorEnrollmentGateResult($user))->toBe('passed-through');
});

it('rejects an invalid code on the setup page without confirming enrollment', function (): void {
    $role = Role::factory()->create(['requires_two_factor' => true]);
    $user = User::factory()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
    $user->assignRole($role);

    $this->actingAs($user);
    $this->get(route('auth.two-factor.setup'));

    $this->post(route('auth.two-factor.setup.store'), ['code' => '000000'])
        ->assertRedirect()
        ->assertSessionHasErrors('code');

    expect($user->fresh()?->two_factor_confirmed_at)->toBeNull();
});

it('does not force setup on a user whose role does not require 2FA', function (): void {
    $user = User::factory()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

    expect(twoFactorEnrollmentGateResult($user))->toBe('passed-through');
});

it('does not force setup on a user who has already completed enrollment', function (): void {
    $twoFactor = app(TwoFactorService::class);
    $role = Role::factory()->create(['requires_two_factor' => true]);
    $user = User::factory()->create([
        'two_factor_secret' => $twoFactor->generateSecret(),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    expect(twoFactorEnrollmentGateResult($user))->toBe('passed-through');
});

it('lets the setup page and store routes through without redirecting', function (): void {
    $role = Role::factory()->create(['requires_two_factor' => true]);
    $user = User::factory()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
    $user->assignRole($role);

    expect(twoFactorEnrollmentGateResult($user, 'auth.two-factor.setup'))->toBe('passed-through')
        ->and(twoFactorEnrollmentGateResult($user, 'auth.two-factor.setup.store'))->toBe('passed-through')
        ->and(twoFactorEnrollmentGateResult($user, 'auth.logout'))->toBe('passed-through');
});

it('still allows logging out while enrollment is pending', function (): void {
    $role = Role::factory()->create(['requires_two_factor' => true]);
    $user = User::factory()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
    $user->assignRole($role);

    $this->actingAs($user)
        ->post(route('auth.logout'))
        ->assertRedirect();

    $this->assertGuest();
});
