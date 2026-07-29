<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Magna\Admin\AdminPanelProvider;
use Magna\Auth\Http\Controllers\EmailVerificationController;
use Magna\Auth\Http\Controllers\ForgotPasswordController;
use Magna\Auth\Http\Controllers\LogoutController;
use Magna\Auth\Http\Controllers\RegisterController;
use Magna\Auth\Http\Controllers\ResetPasswordController;
use Magna\Auth\Http\Controllers\TwoFactorChallengeController;
use Magna\Auth\Http\Controllers\TwoFactorSetupController;
use Magna\Auth\Http\Middleware\EnsureTwoFactorEnrolled;

/*
|--------------------------------------------------------------------------
| Magna Auth — Web (session) routes
|--------------------------------------------------------------------------
| Prefix: /auth  (set in AuthServiceProvider)
| Security headers are applied by the SecurityHeadersMiddleware added to
| the web group in bootstrap/app.php; CSP is added for admin paths in Stage 10.
*/

// Guest-only routes
Route::middleware('guest')->group(function (): void {
    // The panel's Filament login is the single sign-in page. This legacy route
    // only redirects there so old links/bookmarks never hit a second login.
    Route::get('/login', fn () => redirect()->route(AdminPanelProvider::loginRoute()))->name('auth.login');

    Route::get('/register', [RegisterController::class, 'showForm'])->name('auth.register');
    // Stage 3 (C3-01): every sibling auth-mutation route already has a
    // throttle — registration was the one gap, leaving it open to mass
    // account creation and email-enumeration-via-unique-validation-error.
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.register.store');

    Route::get('/forgot-password', [ForgotPasswordController::class, 'showForm'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendLink'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showForm'])->name('password.reset');
    // S1-13: was missing the throttle its sibling password.email already has.
    Route::post('/reset-password', [ResetPasswordController::class, 'reset'])
        ->middleware('throttle:10,1')
        ->name('password.update');

    // 2FA challenge (reached after password auth, before session is fully established)
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'showForm'])
        ->name('auth.two-factor.challenge');
    // A 6-digit TOTP is ~1M combinations and the verifier accepts a small
    // window of them at any moment, so an unthrottled challenge is brute-
    // forceable in minutes by anyone who already has the password. The route
    // throttle is the coarse ceiling; TwoFactorChallengeController additionally
    // applies the per-identity LoginThrottle and refuses replayed codes.
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'verify'])
        ->middleware('throttle:5,1')
        ->name('auth.two-factor.challenge.verify');
});

// Authenticated routes
Route::middleware(['auth', EnsureTwoFactorEnrolled::class])->group(function (): void {
    Route::post('/logout', LogoutController::class)->name('auth.logout');

    // Email verification
    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // 2FA management (JSON API-style, consumed by admin UI in Stage 10)
    Route::post('/two-factor/enrol', [TwoFactorSetupController::class, 'enrol'])
        ->name('auth.two-factor.enrol');
    Route::post('/two-factor/confirm', [TwoFactorSetupController::class, 'confirm'])
        ->name('auth.two-factor.confirm');
    Route::delete('/two-factor', [TwoFactorSetupController::class, 'disable'])
        ->name('auth.two-factor.disable');
    Route::get('/two-factor/recovery-codes', [TwoFactorSetupController::class, 'recoveryCodes'])
        ->name('auth.two-factor.recovery-codes');
    Route::post('/two-factor/recovery-codes', [TwoFactorSetupController::class, 'regenerateRecoveryCodes'])
        ->name('auth.two-factor.recovery-codes.regenerate');

    // S1-06: mandatory-enrollment setup page. Not wrapped in
    // EnsureTwoFactorEnrolled (it's registered separately in
    // AuthServiceProvider) — this route group is exactly what that
    // middleware must let through to avoid a redirect loop.
    Route::get('/two-factor-setup', [TwoFactorSetupController::class, 'showSetupForm'])
        ->name('auth.two-factor.setup');
    Route::post('/two-factor-setup', [TwoFactorSetupController::class, 'storeSetup'])
        ->name('auth.two-factor.setup.store');
});
