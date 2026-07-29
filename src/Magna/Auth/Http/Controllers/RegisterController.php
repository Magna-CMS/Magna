<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Controllers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Magna\Auth\PasswordRules;
use Magna\Settings\GeneralSettings;
use Magna\Settings\SecuritySettings;
use Magna\Users\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RegisterController extends Controller
{
    public function showForm(): never
    {
        $this->guardEnabled();

        // Blade view rendered by routes; this method is never reachable
        // because guardEnabled() always throws when registration is off.
        // The route handler calls showForm() only when enabled.
        throw new NotFoundHttpException;
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guardEnabled();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'confirmed', PasswordRules::defaults()],
        ]);

        $user = User::create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
        ]);

        event(new Registered($user));

        if (SecuritySettings::get()->require_email_verification) {
            return redirect()->route('verification.notice');
        }

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function guardEnabled(): void
    {
        if (! GeneralSettings::get()->registration_enabled) {
            throw new NotFoundHttpException;
        }
    }
}
