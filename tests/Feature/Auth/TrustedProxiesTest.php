<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * TRUSTED_PROXIES has to actually reach the TrustProxies middleware.
 *
 * It used to be applied with a trustProxies() call in the withMiddleware()
 * callback in bootstrap/app.php. That callback runs when the HTTP kernel is
 * resolved — before the config repository is bound — so the config() lookup
 * feeding it always returned null and the call was never made. Every
 * deployment behind a proxy therefore recorded the proxy's address in the
 * audit log, throttled logins against it, and treated an https site as
 * plaintext, while the setting looked set.
 */
function runTrustProxies(Request $request): void
{
    (new TrustProxies)->handle($request, fn (): Response => new Response);
}

function forwardedRequest(): Request
{
    return Request::create('http://example.test/login', server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);
}

afterEach(function (): void {
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

it('reads the trusted proxy list from config at request time', function (): void {
    config(['trustedproxy.proxies' => ['127.0.0.1']]);

    $request = forwardedRequest();
    runTrustProxies($request);

    expect($request->ip())->toBe('203.0.113.7')
        ->and($request->isSecure())->toBeTrue();
});

it('ignores forwarded headers from an address that is not a trusted proxy', function (): void {
    // The whole point of the list. X-Forwarded-For is client-supplied, so an
    // untrusted sender must not be able to pick the IP that gets logged and
    // throttled — or claim the connection was secure.
    config(['trustedproxy.proxies' => ['10.9.9.9']]);

    $request = forwardedRequest();
    runTrustProxies($request);

    expect($request->ip())->toBe('127.0.0.1')
        ->and($request->isSecure())->toBeFalse();
});

it('trusts nothing when TRUSTED_PROXIES is unset', function (): void {
    config(['trustedproxy.proxies' => null]);

    $request = forwardedRequest();
    runTrustProxies($request);

    expect($request->ip())->toBe('127.0.0.1')
        ->and($request->isSecure())->toBeFalse();
});

it('parses the environment value into the shape TrustProxies expects', function (string $raw, array|string|null $expected): void {
    $original = $_ENV['TRUSTED_PROXIES'] ?? null;

    $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $raw;
    putenv("TRUSTED_PROXIES={$raw}");

    try {
        $config = require base_path('config/trustedproxy.php');
    } finally {
        if ($original === null) {
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
            putenv('TRUSTED_PROXIES');
        } else {
            $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $original;
            putenv("TRUSTED_PROXIES={$original}");
        }
    }

    expect($config['proxies'])->toBe($expected);
})->with([
    'single address' => ['127.0.0.1', ['127.0.0.1']],
    'comma-separated list, loosely spaced' => ['127.0.0.1, ::1 ,10.0.0.0/8', ['127.0.0.1', '::1', '10.0.0.0/8']],
    'trust the single hop in front' => ['*', '*'],
    'empty means trust nothing' => ['', null],
]);
