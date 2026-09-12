<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S1-10: force the session cookie's Secure flag in production whenever the
 * request actually arrived over TLS, regardless of SESSION_SECURE_COOKIE — a
 * deployment that copies .env.example verbatim and forgets to set it would
 * otherwise ship a session cookie that can legally travel over plaintext HTTP.
 *
 * This lives in middleware rather than a service provider's boot(), and both
 * halves of that matter:
 *
 * - It has to run AFTER TrustProxies, which is a global middleware. A provider
 *   boots long before any middleware, so request()->isSecure() could not see
 *   X-Forwarded-Proto and the flag was never set on a site whose TLS is
 *   terminated by a proxy or CDN — precisely the deployments that need it.
 * - It has to be decided PER REQUEST. From a provider the decision is made once
 *   per process against whatever request-shaped object happens to exist, and is
 *   then frozen: under `config:cache` that object is built from APP_URL by
 *   Laravel's SetRequestForConsole (https on any site with a domain), which
 *   baked session.secure => true into the cached config of plain-HTTP installs;
 *   under Octane the booted worker froze the same wrong answer for every
 *   request it went on to serve. Either way the browser discarded the cookie
 *   and the login form answered "Page Expired" (419) forever, with nothing in
 *   the log.
 *
 * Appended to the GLOBAL middleware stack (see bootstrap/app.php): appending
 * lands it after TrustProxies, and global middleware runs before any route
 * group's EncryptCookies / StartSession, so the value is in place by the time
 * the session cookie is built — on every session-starting route, whichever
 * middleware groups a panel or plugin chooses to include.
 *
 * A plaintext request is left alone: config/session.php still honours
 * SESSION_SECURE_COOKIE for an operator who terminates TLS somewhere this
 * server cannot observe, and ForceHttpsMiddleware keeps plaintext requests from
 * reaching session issuance at all when "Force HTTPS" is on.
 */
class SecureSessionCookieMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && $request->isSecure()) {
            config(['session.secure' => true]);
        }

        return $next($request);
    }
}
