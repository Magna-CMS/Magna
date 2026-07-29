<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks access until the 2FA challenge is completed when:
 *  - the session has a pending 2FA user (set by the Filament login page), AND
 *  - the user has confirmed 2FA enrollment.
 *
 * Routes that are part of the challenge flow itself are excluded via
 * except() or by not attaching this middleware to those routes.
 */
class EnsureTwoFactorAuthenticated
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('auth.two_factor_user_id')) {
            if ($request->expectsJson()) {
                return response()->json(
                    ['message' => 'Two-factor authentication required.'],
                    Response::HTTP_FORBIDDEN,
                );
            }

            return redirect()->route('auth.two-factor.challenge');
        }

        return $next($request);
    }
}
