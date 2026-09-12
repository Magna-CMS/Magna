<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Magna\Auth\Http\Middleware\SecureSessionCookieMiddleware;

/**
 * A production install served over plain HTTP must still be signable-in.
 *
 * The session cookie's Secure flag used to be forced on for every production
 * request once the installer had finished. Browsers discard a Secure cookie
 * that arrives from an http:// origin, so the install completed and then no
 * one could ever log in: each attempt authenticated, wrote a session row with
 * the user id, lost the cookie, and redirected back to the form — with nothing
 * in the log, because nothing threw.
 *
 * The decision was then made once per process in AuthServiceProvider::boot().
 * That reintroduced the same failure twice over — `config:cache` and Octane
 * both freeze one answer, taken from a request built out of APP_URL — and it
 * could never set the flag on a site whose TLS ends at a proxy, because a
 * provider boots before TrustProxies. It is per-request middleware now.
 */
function runSecureSessionMiddleware(Request $request): void
{
    (new SecureSessionCookieMiddleware)->handle($request, fn (): Response => new Response);
}

it('does not force the secure cookie flag on a plain HTTP production request', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['session.secure' => null]);

    runSecureSessionMiddleware(Request::create('http://example.test/login'));

    expect(config('session.secure'))->not->toBeTrue();
});

it('forces the secure cookie flag when the production request is over TLS', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['session.secure' => null]);

    runSecureSessionMiddleware(Request::create('https://example.test/login'));

    expect(config('session.secure'))->toBeTrue();
});

it('forces the flag when a trusted proxy reports the client used TLS', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['session.secure' => null]);

    // What every proxied deployment looks like: Cloudflare (or nginx, or a load
    // balancer) terminates TLS and forwards plaintext to the app with the
    // original scheme in a header. Deciding this from a service provider could
    // never see it — TrustProxies is middleware, and by the time it runs, every
    // provider has already booted.
    $request = Request::create('http://example.test/login', server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

    try {
        runSecureSessionMiddleware($request);
    } finally {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    expect(config('session.secure'))->toBeTrue();
});

it('does not let one TLS request set the flag for a later plaintext one', function (): void {
    // The frozen-answer bug, from the other direction: the panel is reachable
    // both through the TLS tunnel and directly on the LAN, and under Octane one
    // long-lived worker serves both. Whatever the https request decided must
    // not still be in force when the http one arrives.
    app()->detectEnvironment(fn (): string => 'production');
    config(['session.secure' => null]);

    runSecureSessionMiddleware(Request::create('https://example.test/login'));
    expect(config('session.secure'))->toBeTrue();

    config(['session.secure' => null]); // Octane resets config between requests.
    runSecureSessionMiddleware(Request::create('http://192.168.1.8/login'));

    expect(config('session.secure'))->not->toBeTrue();
});

it('still honours an explicit SESSION_SECURE_COOKIE opt-in', function (): void {
    // An operator terminating TLS upstream sets this so the flag survives even
    // though the PHP process only ever sees plaintext requests.
    config(['session.secure' => true]);

    app()->detectEnvironment(fn (): string => 'production');

    runSecureSessionMiddleware(Request::create('http://example.test/login'));

    expect(config('session.secure'))->toBeTrue();
});

it('is registered in the global middleware stack, after TrustProxies', function (): void {
    // Global, not the 'web' group: a Filament panel declares its own middleware
    // list, so whether an admin route sees a web-group registration depends on
    // whether the panel happens to include 'web' — global covers every
    // session-starting route regardless of that choice. After TrustProxies
    // because that is what makes X-Forwarded-Proto readable.
    $global = app(Kernel::class)->getGlobalMiddleware();

    $position = array_search(SecureSessionCookieMiddleware::class, $global, true);
    $proxies = array_search(TrustProxies::class, $global, true);

    expect($position)->not->toBeFalse()
        ->and($proxies)->not->toBeFalse()
        ->and($position)->toBeGreaterThan($proxies);
});
