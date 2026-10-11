<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Magna\Licensing\LicenseClient;

/**
 * A seat is a domain string, so core and the plugins must send the same one.
 *
 * The marketplace records an activation's `site_domain` from whatever the
 * client sends and counts seats on it. Core sent APP_URL whole
 * (`https://example.test`) while a plugin activating the very same install
 * sends the parsed host (`example.test`) — two different strings for one
 * machine, which on a single-seat licence reads as a seat overrun and leaves
 * the account showing a site twice under two spellings of its address.
 *
 * Asserted on the outgoing request rather than the result: the response is a
 * signed envelope the client declines to open under test keys, so the payload
 * is the only place the behaviour is visible.
 */
function activationDomainSent(string $appUrl): ?string
{
    config(['app.url' => $appUrl]);

    Http::fake(['*/license/activate' => Http::response(['error' => 'invalid_key'], 422)]);

    app(LicenseClient::class)->activate('MAGNA-TEST-KEY');

    $sent = null;

    Http::assertSent(function ($request) use (&$sent): bool {
        if (str_contains($request->url(), '/license/activate')) {
            $sent = $request['domain'];
        }

        return true;
    });

    return is_string($sent) ? $sent : null;
}

it('activates against the bare host, not the whole APP_URL', function (): void {
    expect(activationDomainSent('https://example.test'))->toBe('example.test');
});

it('drops a path and a trailing slash from APP_URL', function (): void {
    // A site installed in a subdirectory still holds one seat, on one host.
    expect(activationDomainSent('https://example.test/panel/'))->toBe('example.test');
});

it('keeps the host alone when APP_URL carries a port', function (): void {
    // The port belongs to the origin, not to the seat: the plugins parse the
    // host out too, and a seat that disagreed with them is the whole fault.
    expect(activationDomainSent('http://example.test:8080'))->toBe('example.test');
});

it('sends an empty domain rather than a malformed one', function (): void {
    // An install that cannot say where it lives sends nothing and is refused
    // by the marketplace, which is better than booking a seat on garbage.
    expect(activationDomainSent(''))->toBe('');
});
