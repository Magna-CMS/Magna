<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Magna\Users\User;

it('shows the forgot password form', function (): void {
    $this->get(route('password.request'))->assertOk();
});

it('sends a reset link for a known email', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect()
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class);
});

it('returns the same status for unknown emails (prevents enumeration)', function (): void {
    $this->post(route('password.email'), ['email' => 'nobody@example.com'])
        ->assertRedirect()
        ->assertSessionHas('status');
});

it('resets password with a valid token', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Str0ngN3wPassphrase',
        'password_confirmation' => 'Str0ngN3wPassphrase',
    ])->assertRedirect(route('filament.magna.auth.login'))
        ->assertSessionHas('status');

    // Verify the new password hashes correctly
    expect(auth()->attempt(['email' => $user->email, 'password' => 'Str0ngN3wPassphrase']))->toBeTrue();
});

it('rejects reset with an invalid token', function (): void {
    $user = User::factory()->create();

    $this->post(route('password.update'), [
        'token' => 'bad-token',
        'email' => $user->email,
        'password' => 'Str0ngN3wPassphrase',
        'password_confirmation' => 'Str0ngN3wPassphrase',
    ])->assertRedirect()->assertSessionHasErrors('email');
});

// S1-13: password.update previously had no rate limit at all, unlike its
// sibling password.email (throttle:6,1).
it('rate limits repeated reset-password submission attempts', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $this->post(route('password.update'), [
            'token' => 'bad-token',
            'email' => $user->email,
            'password' => 'Str0ngN3wPassphrase',
            'password_confirmation' => 'Str0ngN3wPassphrase',
        ])->assertStatus(302);
    }

    $this->post(route('password.update'), [
        'token' => 'bad-token',
        'email' => $user->email,
        'password' => 'Str0ngN3wPassphrase',
        'password_confirmation' => 'Str0ngN3wPassphrase',
    ])->assertStatus(429);
});
