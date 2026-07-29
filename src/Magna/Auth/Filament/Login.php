<?php

declare(strict_types=1);

namespace Magna\Auth\Filament;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Magna\Auth\LoginThrottle;
use Magna\Users\User;

/**
 * The single sign-in page for the panel. Filament's default login only checks
 * a password; this override adds the two behaviours the app needs on top:
 *
 *  - Magna's configurable per-identity brute-force lockout (LoginThrottle),
 *    stronger and more tunable than Filament's coarse per-session rate limit.
 *  - The two-factor challenge.
 *
 * A user with confirmed 2FA is diverted to the challenge *before* any session
 * login happens, so no Login event / "login success" audit entry is written
 * until the second factor is actually verified (in TwoFactorChallengeController).
 * Every other path — non-2FA users, wrong password, suspended accounts — is
 * handled by the parent, which fires the correct Login/Failed events, so the
 * audit trail stays truthful. This is the only login entry point.
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $throttle = app(LoginThrottle::class);
        $data = $this->form->getState();
        $email = is_string($data['email'] ?? null) ? $data['email'] : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';

        if ($throttle->isLocked($email)) {
            throw ValidationException::withMessages([
                'data.email' => __('Too many login attempts. Please try again in :seconds seconds.', [
                    'seconds' => $throttle->availableIn($email),
                ]),
            ]);
        }

        // Peek at the credentials WITHOUT logging in. If they belong to a
        // 2FA-enrolled user who may access the panel, divert to the challenge
        // now — no session, no Login event, no audit entry yet. (Password is
        // only hashed once we know the account has 2FA, so this adds no timing
        // signal for ordinary accounts.)
        $user = User::query()->where('email', $email)->first();
        $panel = Filament::getCurrentPanel() ?? Filament::getDefaultPanel();

        if (
            $user instanceof User
            && $user->two_factor_confirmed_at !== null
            && Hash::check($password, $user->getAuthPassword())
            && $user->canAccessPanel($panel)
        ) {
            $throttle->clear($email);

            session()->put('auth.two_factor_user_id', $user->getKey());
            session()->put('auth.two_factor_remember', (bool) ($data['remember'] ?? false));
            session()->save();

            $this->redirect(route('auth.two-factor.challenge'));

            return null;
        }

        // Everything else: let Filament validate + log in (or throw). A
        // credential failure counts toward the lockout.
        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            $throttle->hit($email);

            throw $exception;
        }

        if ($response !== null) {
            $throttle->clear($email);
        }

        return $response;
    }
}
