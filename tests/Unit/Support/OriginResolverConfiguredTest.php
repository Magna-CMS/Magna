<?php

declare(strict_types=1);

use Magna\Support\OriginResolver;
use Tests\TestCase;

/**
 * The address this install calls its own, read in one place.
 *
 * Both of these were hand-rolled at their single call site out of the same
 * parse_url(config('app.url')) — the account handshake wanted the whole origin
 * to compare against the browsing one, the licence client wanted the bare host
 * to book a seat with. Two spellings of one definition is how OriginResolver's
 * own docblock says the first disagreement started, so they live together now
 * and are pinned together here.
 *
 * The seat case is the one with teeth: a licence seat is identified by its
 * domain string, and the plugins that activate the same install send the parsed
 * host. A caller sending the whole URL books one machine onto two seat strings
 * on a licence that commonly carries one.
 */

// config() is a container binding, so these want a booted application rather
// than the bare container the Unit suite otherwise gives — same reason
// ConfigDriftTest declares it.
uses(TestCase::class);

function withAppUrl(mixed $url, callable $case): void
{
    $original = config('app.url');

    try {
        config(['app.url' => $url]);
        $case();
    } finally {
        config(['app.url' => $original]);
    }
}

it('reads the bare host out of a plain address', function (): void {
    withAppUrl('https://example.test', function (): void {
        expect(OriginResolver::configuredHost())->toBe('example.test');
    });
});

it('drops a path and a trailing slash', function (): void {
    // A site installed in a subdirectory still holds one seat, on one host.
    withAppUrl('https://example.test/panel/', function (): void {
        expect(OriginResolver::configuredHost())->toBe('example.test');
    });
});

it('leaves a port out of the host', function (): void {
    // The port belongs to the origin, not to the seat — and the plugins parse
    // the host out too, so a seat that disagreed with them is the whole fault.
    withAppUrl('http://example.test:8080', function (): void {
        expect(OriginResolver::configuredHost())->toBe('example.test');
    });
});

it('has no opinion when the install cannot say where it lives', function (): void {
    // Mid-installation, and a caller that refuses everything on a site which
    // has not been told its own name is a worse failure than the one it guards.
    foreach (['', 'not a url', null, 42] as $absent) {
        withAppUrl($absent, function (): void {
            expect(OriginResolver::configuredHost())->toBeNull()
                ->and(OriginResolver::configuredOrigin())->toBeNull();
        });
    }
});

it('gives the whole origin, scheme included', function (): void {
    withAppUrl('https://example.test/panel', function (): void {
        expect(OriginResolver::configuredOrigin())->toBe('https://example.test');
    });
});

it('keeps a port the scheme does not imply', function (): void {
    withAppUrl('http://192.168.1.8:8080', function (): void {
        expect(OriginResolver::configuredOrigin())->toBe('http://192.168.1.8:8080');
    });
});

it('omits a port the scheme already implies', function (): void {
    // getSchemeAndHttpHost() omits them, so an APP_URL written with :443 must
    // not read as a different origin from the request that matches it.
    withAppUrl('https://example.test:443', function (): void {
        expect(OriginResolver::configuredOrigin())->toBe('https://example.test');
    });

    withAppUrl('http://example.test:80', function (): void {
        expect(OriginResolver::configuredOrigin())->toBe('http://example.test');
    });
});

it('assumes http only when a scheme is genuinely absent', function (): void {
    // A protocol-relative address has a host and no scheme, and guessing https
    // would make the guard compare against one the browser never reports.
    withAppUrl('//example.test', function (): void {
        expect(OriginResolver::configuredOrigin())->toBe('http://example.test');
    });
});

it('reads a bare hostname as no address at all', function (): void {
    /*
     * `example.test` with no scheme is a path to parse_url, not a host, so
     * there is nothing here to call this install's own address. Null is the
     * honest answer and the guards hold no opinion — inventing a scheme would
     * have them compare against an origin nobody is browsing.
     */
    withAppUrl('example.test', function (): void {
        expect(OriginResolver::configuredHost())->toBeNull()
            ->and(OriginResolver::configuredOrigin())->toBeNull();
    });
});
