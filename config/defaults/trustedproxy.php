<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies - the copy that travels with core
|--------------------------------------------------------------------------
|
| `config/trustedproxy.php` returns this. It is here rather than only there
| because `config/defaults` is a core-owned update path and `config/` is not:
| this file was ADDED in 1.4.1, so every site that updated from 1.4.0 or
| earlier has no trustedproxy.php at all. On those installs TRUSTED_PROXIES
| does nothing, the real client IP is lost from the audit log and from login
| throttling, and isSecure() reads false behind a proxy. Backfilling the
| namespace reaches them without the file needing to exist.
|
| See Magna\Support\ConfigDefaults.
*/

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Trusted Proxies
|--------------------------------------------------------------------------
|
| Behind a reverse proxy, load balancer or CDN the real client IP arrives in
| X-Forwarded-For and the original scheme in X-Forwarded-Proto. Without a
| trusted-proxy list Laravel ignores both, so request()->ip() is the proxy —
| which is what the audit log records and what login throttling counts — and
| request()->isSecure() is false on a site that is plainly https to its users.
|
| Trust nothing by default: X-Forwarded-* is client-supplied and spoofable, so
| trusting the wrong thing lets any visitor choose the IP that gets throttled
| and logged. Name the proxy explicitly per deployment via TRUSTED_PROXIES —
| a comma-separated list of addresses or CIDR ranges. Always prefer the
| explicit list: '*' trusts every inbound hop, including X-Forwarded-Host,
| which then lets any client rewrite the host that password-reset links and
| other absolute URLs are built from. Reserve '*' for the one topology where
| it is sound — a single trusted reverse proxy that strips inbound
| forwarding headers before adding its own — and treat it as a last resort
| even there.
|
| This file exists rather than a trustProxies() call in bootstrap/app.php
| because the middleware configuration callback there runs when the HTTP
| kernel is resolved, which is BEFORE the config repository is bound — so any
| config() lookup in it silently returns null and the list is never applied.
| Illuminate\Http\Middleware\TrustProxies falls back to reading
| trustedproxy.proxies when it handles a request, by which point config is
| loaded (and cached), so the value set here is the one that actually takes
| effect.
|
*/

$trustedProxies = env('TRUSTED_PROXIES');

return [

    'proxies' => match (true) {
        $trustedProxies === '*' => '*',
        is_string($trustedProxies) && trim($trustedProxies) !== '' => array_values(array_filter(
            array_map(trim(...), explode(',', $trustedProxies)),
            static fn (string $proxy): bool => $proxy !== '',
        )),
        default => null,
    },

];
