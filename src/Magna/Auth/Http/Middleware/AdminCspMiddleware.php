<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy for the admin panel.
 *
 * Wired onto the panel's middleware stack in AdminPanelProvider — this
 * class existed and was aliased for months while attached to NOTHING, so
 * the panel ran with no CSP at all; the alias is kept for routes that opt
 * in individually.
 *
 * The policy is the tightest one the current stack actually runs under:
 * Filament 5 is Livewire + Alpine, which needs 'unsafe-inline' (Livewire
 * injects inline component scripts) and 'unsafe-eval' (Alpine evaluates
 * attribute expressions). The nonce step that retires 'unsafe-inline'
 * (§C5) is deliberately staged behind live verification of Livewire's
 * script injection under a nonce policy — shipping it blind would risk a
 * dead panel; until then this still blocks remote script/style/font
 * injection, plugin exfiltration to foreign origins (connect-src), and
 * form-action hijacking.
 *
 * An explicitly set policy on the response is respected, same contract as
 * SecurityHeadersMiddleware: a route that declares its own framing/CSP
 * intent (the Pages builder canvas) did so deliberately.
 */
class AdminCspMiddleware
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->headers->has('Content-Security-Policy')) {
            return $response;
        }

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'", // Livewire inline + Alpine eval; nonce step staged (§C5)
            "style-src 'self' 'unsafe-inline'",                // Filament inline styles
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",                              // Livewire XHR only ever talks home
            "frame-src 'self'",                                // preview/canvas iframes are same-origin
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
