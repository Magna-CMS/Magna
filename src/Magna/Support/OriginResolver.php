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
 *
 * The configured pair below answer the other half of the question — not where
 * the browser is, but the address this install was told it lives at. Both
 * belong here for the reason the first pair do: they were each hand-rolled at
 * their one call site, out of the same parse_url(config('app.url')), and two
 * copies of a definition is how the first disagreement started.
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

    /**
     * The host this install calls its own, from APP_URL.
     *
     * The bare host, because a licence seat is identified by its domain string
     * and a plugin activating the same install sends the parsed host. A caller
     * sending the whole URL where a plugin sends the host books one machine
     * onto two seat strings, on a licence that commonly carries one.
     *
     * Null when APP_URL is absent or unparseable. Mid-installation that is the
     * honest answer, and a caller that refuses everything on a site which has
     * not yet been told its own name is a worse failure than the one it guards.
     */
    public static function configuredHost(): ?string
    {
        return self::configuredParts()['host'] ?? null;
    }

    /**
     * The whole origin this install calls its own: scheme, host, and a port
     * only where the scheme does not already imply it.
     *
     * Default ports are omitted to match getSchemeAndHttpHost(), so an APP_URL
     * carrying :443 on https still equals the origin a browser reports for the
     * same address. Kept as a whole origin rather than a bare host because the
     * scheme and the port are part of the string an account stores against a
     * site, and http and https on one name are two sites to it.
     */
    public static function configuredOrigin(): ?string
    {
        $parts = self::configuredParts();

        if ($parts === null) {
            return null;
        }

        $implied = ($parts['scheme'] === 'https' && $parts['port'] === 443)
            || ($parts['scheme'] === 'http' && $parts['port'] === 80);

        $port = $parts['port'] === null || $implied ? '' : ':'.$parts['port'];

        return $parts['scheme'].'://'.$parts['host'].$port;
    }

    /** @return array{scheme: string, host: string, port: int|null}|null */
    private static function configuredParts(): ?array
    {
        $url = config('app.url');

        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        return [
            'scheme' => is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'http',
            'host' => $parts['host'],
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
        ];
    }
}
