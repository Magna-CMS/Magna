<?php

declare(strict_types=1);

namespace Magna\Support;

use Illuminate\Http\Request;

/**
 * The one definition of "this request's origin".
 *
 * Magna used to hold two: AccountCentre conducted its handshake on the
 * browsing origin while the management API's cross-origin guard compared
 * against APP_URL — so on an install reached at a second address (a LAN IP,
 * a staging alias, a panel on its own hostname) the same browser was
 * same-origin to one feature and cross-origin to the other, and management
 * calls 403'd on the very host the administrator was using.
 *
 * The request's own scheme+host+port is the right answer everywhere: it is
 * the origin the browser actually sent its headers for, and it is bounded —
 * TrustHosts (bootstrap/app.php) refuses any request whose claimed host is
 * not APP_URL's, a subdomain of it, or on the MAGNA_TRUSTED_HOSTS list, so
 * "follows the browser" can never mean "follows an attacker's Host header".
 */
final class OriginResolver
{
    /** Scheme + host [+ non-default port] of the request, no trailing slash. */
    public static function requestOrigin(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

    /**
     * Whether a browser-sent Origin header names this request's own origin.
     * Browsers send the bare origin (never a path, default ports omitted),
     * which is exactly the shape getSchemeAndHttpHost() produces.
     */
    public static function isSameRequestOrigin(string $origin, Request $request): bool
    {
        return rtrim($origin, '/') === self::requestOrigin($request);
    }
}
