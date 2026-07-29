<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Magna\Admin\AdminPanelProvider;
use Magna\Auth\PasswordRules;
use Magna\Auth\SessionInvalidator;
use Magna\Users\User;

class ResetPasswordController extends Controller
{
    public function showForm(Request $request, string $token): View
    {
        return view('magna::reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            // PasswordRules::defaults() — a shared minimum, so the reset form
            // can never end up weaker than registration. `min:8` alone was
            // below baseline for an admin panel.
            'password' => ['required', 'string', 'confirmed', PasswordRules::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Every other way into this account dies with the old password.
                // Deleting API tokens and rotating remember_token is not
                // enough on its own: an attacker who already holds a live
                // session cookie keeps it through the victim's reset, which
                // is exactly the persistence a reset is meant to break.
                $user->tokens()->delete();
                SessionInvalidator::forUser($user);

                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PasswordReset) {
            return redirect()->route(AdminPanelProvider::loginRoute())->with('status', $status);
        }

        return back()->withErrors(['email' => $status])->withInput($request->only('email'));
    }
}
