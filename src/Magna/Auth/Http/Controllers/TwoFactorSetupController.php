<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Magna\Auth\TwoFactorService;
use Magna\Users\User;

class TwoFactorSetupController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    /**
     * S1-06: mandatory-2FA setup page. Reached automatically via
     * EnsureTwoFactorEnrolled whenever the authenticated user's role
     * requires 2FA and they haven't confirmed enrollment yet — this is the
     * page that closes the "requires_two_factor is set but enrollment is
     * never actually forced" gap. Also reachable voluntarily from the
     * profile page's "Enable 2FA" link.
     */
    public function showSetupForm(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $secret = $user->two_factor_secret;
        if ($secret === null) {
            $secret = $this->twoFactor->generateSecret();
            $user->forceFill(['two_factor_secret' => $secret])->save();
        }

        return view('magna::two-factor-setup', [
            'qrCodeSvg' => $this->twoFactor->getQrCodeSvg($user->email, $secret),
            'secret' => $secret,
        ]);
    }

    /** Confirms enrollment from the setup page's form (mirrors confirm(), but redirects instead of returning JSON). */
    public function storeSetup(Request $request): RedirectResponse|View
    {
        $request->validate(['code' => ['required', 'string']]);

        /** @var User $user */
        $user = Auth::user();

        if ($user->two_factor_secret === null) {
            return redirect()->route('auth.two-factor.setup');
        }

        if (! $this->twoFactor->verify($user->two_factor_secret, $request->string('code')->toString())) {
            return back()->withErrors(['code' => 'Invalid code. Please try again.']);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

        $request->session()->regenerate();

        return view('magna::two-factor-setup-complete', ['recoveryCodes' => $codes]);
    }

    /** Begin enrollment: generate and store a new secret (unconfirmed). */
    public function enrol(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $secret = $this->twoFactor->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        // Rotate session on privilege change (new 2FA secret = privilege change).
        $request->session()->regenerate();

        return response()->json([
            'secret' => $secret,
            'qr_code' => $this->twoFactor->getQrCodeSvg($user->email, $secret),
        ]);
    }

    /** Confirm enrollment by verifying a TOTP code against the pending secret. */
    public function confirm(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        /** @var User $user */
        $user = Auth::user();

        if ($user->two_factor_secret === null) {
            return response()->json(['message' => 'No pending 2FA enrollment.'], 422);
        }

        if (! $this->twoFactor->verify($user->two_factor_secret, $request->string('code')->toString())) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

        return response()->json(['recovery_codes' => $codes]);
    }

    /** Disable 2FA for the authenticated user (requires password confirmation). */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        /** @var User $user */
        $user = Auth::user();

        if (! Hash::check($request->string('password')->toString(), (string) $user->getAuthPassword())) {
            return response()->json(['message' => 'Invalid password.'], 422);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        // S1-12: revoke all API tokens on 2FA disable, matching the same
        // defensive pattern ResetPasswordController::reset() already uses.
        // Without this, a management token minted while 2FA was enrolled
        // stays fully valid even after 2FA is turned off — e.g. by an
        // attacker who has obtained the account password and wants to
        // remove the second factor without losing existing sessions/tokens.
        $user->tokens()->delete();

        $request->session()->regenerate();

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    /** Regenerate recovery codes (requires confirmed 2FA). */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->two_factor_confirmed_at === null) {
            return response()->json(['message' => '2FA is not confirmed.'], 422);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return response()->json(['recovery_codes' => $codes]);
    }

    /** Show current recovery codes (requires confirmed 2FA). */
    public function recoveryCodes(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->two_factor_confirmed_at === null) {
            return response()->json(['message' => '2FA is not confirmed.'], 422);
        }

        return response()->json(['recovery_codes' => $user->two_factor_recovery_codes ?? []]);
    }
}
