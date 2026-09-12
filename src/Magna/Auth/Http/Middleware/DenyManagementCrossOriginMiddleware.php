<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Magna\Support\OriginResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Explicitly deny cross-origin browser requests to the management API.
 *
 * The browser same-origin policy already blocks responses without CORS headers,
 * but an explicit 403 here adds defence-in-depth: it catches misconfigured
 * clients and makes the policy visible in logs and tests.
 *
 * "Same origin" means the origin of THIS request, not APP_URL: on an install
 * reached at a second trusted address the panel's own management calls used
 * to 403 because their origin matched the browser and this guard matched the
 * config. A cross-site attacker gains nothing from the change — their page's
 * Origin still names their domain, never this request's host, and TrustHosts
 * bounds which hosts a request may claim at all. See OriginResolver.
 *
 * Delivery routes have their own CORS policy (config/cors.php) and must NOT
 * use this middleware.
 */
final class DenyManagementCrossOriginMiddleware
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin !== null && ! OriginResolver::isSameRequestOrigin($origin, $request)) {
            return response()->json(
                ['message' => 'Cross-origin requests are not permitted on the management API.'],
                403,
            );
        }

        return $next($request);
    }
}
