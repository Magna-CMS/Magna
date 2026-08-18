<?php

declare(strict_types=1);

namespace Magna\Auth\Filament;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Magna\Auth\LoginThrottle;
use Magna\Contracts\FailMode;
use Magna\Contracts\LoginCheck;
use Magna\Users\User;
use Throwable;

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
    /**
     * Token from a rendered login check's widget (e.g. Turnstile), set by the
     * widget's JS via `@this.set('captchaToken', …)`. A plain Livewire property
     * rather than a form field so it is not part of the validated form schema.
     */
    public ?string $captchaToken = null;

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

        // Pre-authentication checks (captcha, …) run after the local lockout
        // check — so a locked-out attacker never triggers an outbound provider
        // call — and before any credential work.
        $this->runLoginChecks($data + ['captcha_token' => $this->captchaToken]);

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

    /** The surface identifier the admin panel login is registered under. */
    public const SURFACE = 'magna.admin.login';

    /**
     * Run every registered login check for this surface before credentials are
     * verified. A check denies by throwing ValidationException (rethrown to the
     * form) and permits by returning — it can never turn a denial into an
     * approval, because the real login still runs afterwards. An *unexpected*
     * throwable from a check is a bug: it is reported and then handled per the
     * check's declared fail mode — Open continues (availability first; the
     * remaining gates still stand), Closed denies.
     *
     * @param  array<string, mixed>  $state
     */
    private function runLoginChecks(array $state): void
    {
        $checks = app()->bound('magna.auth.login_checks')
            ? app('magna.auth.login_checks')
            : [];

        if (! is_array($checks)) {
            return;
        }

        foreach ($checks as $check) {
            $instance = is_string($check) ? app($check) : $check;

            if (! $instance instanceof LoginCheck || $instance->surface() !== self::SURFACE) {
                continue;
            }

            try {
                $instance->assert($state, request());
            } catch (ValidationException $e) {
                throw $e;
            } catch (Throwable $e) {
                report($e);

                if ($instance->failMode() === FailMode::Closed) {
                    throw ValidationException::withMessages([
                        'data.email' => __('Sign-in checks could not be completed. Please try again.'),
                    ]);
                }
            }
        }
    }
}
