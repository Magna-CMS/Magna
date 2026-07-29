<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Controllers;

use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(): View|RedirectResponse
    {
        if (request()->user()?->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        return view('magna::verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user();

        // The user resolver is typed as a union across every guard's model, so
        // narrow before dispatching an event that contracts for MustVerifyEmail.
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $request->fulfill();
            event(new Verified($user));
        }

        return redirect()->route('dashboard');
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()?->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        $request->user()?->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
