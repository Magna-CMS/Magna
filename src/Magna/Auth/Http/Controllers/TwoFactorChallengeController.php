<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Controllers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Magna\Admin\AdminPanelProvider;
use Magna\Auth\LoginThrottle;
use Magna\Auth\TwoFactorService;
use Magna\Users\User;

/**
 * The second factor, and the only place a session is established for a
 * 2FA-enrolled user (the Filament login page diverts here before logging in).
 *
 * That makes this the last gate in front of the panel, so it carries the same
 * brute-force protections as the first one:
 *
 *  - the route has a request throttle (see Auth/routes/web.php);
 *  - every failure feeds the same LoginThrottle used by the password step, so
 *    attempts across both factors share one exponential lockout;
 *  - an accepted code is burned for the remainder of its validity window,
 *    because a TOTP stays valid for ~90 seconds and would otherwise be
 *    replayable by anyone who observed it.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private TwoFactorService $twoFactor,
        private LoginThrottle $throttle,
    ) {}

    public function showForm(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('auth.two_factor_user_id')) {
            return redirect()->route(AdminPanelProvider::loginRoute());
        }

        return view('magna::two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('auth.two_factor_user_id');

        if ($userId === null) {
            return redirect()->route(AdminPanelProvider::loginRoute());
        }

        /** @var User|null $user */
        $user = User::find($userId);

        if ($user === null) {
            $request->session()->forget(['auth.two_factor_user_id', 'auth.two_factor_remember']);

            return redirect()->route(AdminPanelProvider::loginRoute());
        }

        $identifier = $this->throttleIdentifier($user);

        if ($this->throttle->isLocked($identifier)) {
            return back()->withErrors([
                'code' => __('Too many attempts. Please try again in :seconds seconds.', [
                    'seconds' => $this->throttle->availableIn($identifier),
                ]),
            ]);
        }

        $code = $request->string('code')->toString();
        $recoveryCode = $request->string('recovery_code')->toString();

        if ($code !== '' && $this->verifyTotp($user, $code)) {
            $this->throttle->clear($identifier);

            return $this->completeLogin($request, $user);
        }

        if ($recoveryCode !== '' && $this->verifyRecovery($user, $recoveryCode)) {
            $this->throttle->clear($identifier);

            return $this->completeLogin($request, $user);
        }

        // A failed second factor is a failed login attempt and belongs in the
        // audit trail — without this, an in-progress brute-force is invisible
        // to anyone reading the log, because the password step succeeded.
        $this->throttle->hit($identifier);
        event(new Failed(Auth::getDefaultDriver(), $user, ['email' => $user->email, 'two_factor' => true]));

        return back()->withErrors([
            'code' => __('The provided code was invalid.'),
        ]);
    }

    private function verifyTotp(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if ($secret === null || $this->codeAlreadyUsed($user, $code)) {
            return false;
        }

        if (! $this->twoFactor->verify($secret, $code)) {
            return false;
        }

        $this->burnCode($user, $code);

        return true;
    }

    /**
     * A TOTP remains valid for its whole window, so accepting one twice would
     * let an observed code (shoulder-surfed, phished, read from a proxy log)
     * be reused for up to ~90 seconds. The marker outlives the widest window
     * the config allows.
     */
    private function codeAlreadyUsed(User $user, string $code): bool
    {
        return Cache::has($this->usedCodeKey($user, $code));
    }

    private function burnCode(User $user, string $code): void
    {
        Cache::put($this->usedCodeKey($user, $code), true, now()->addMinutes(5));
    }

    private function usedCodeKey(User $user, string $code): string
    {
        return 'magna.2fa.used:'.hash('sha256', $user->id.'|'.$code);
    }

    /**
     * Keyed on the user, not the submitted code, so failures accumulate
     * against the account being attacked regardless of which codes are tried.
     */
    private function throttleIdentifier(User $user): string
    {
        return 'two-factor:'.$user->id;
    }

    private function verifyRecovery(User $user, string $input): bool
    {
        $codes = $user->two_factor_recovery_codes;

        if ($codes === null || $codes === []) {
            return false;
        }

        $remaining = $this->twoFactor->redeemRecoveryCode($codes, $input);

        if ($remaining === false) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();

        return true;
    }

    private function completeLogin(Request $request, User $user): RedirectResponse
    {
        $remember = (bool) $request->session()->pull('auth.two_factor_remember', false);

        $request->session()->forget('auth.two_factor_user_id');

        Auth::login($user, $remember);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
