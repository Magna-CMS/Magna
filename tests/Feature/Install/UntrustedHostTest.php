<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * A fresh unzip reached at any address but localhost answered a bare Symfony
 * "400 Bad Request" and could never run its own installer: APP_URL does not
 * exist until the installer writes it, so TrustHosts was matching every
 * request against config's `http://localhost` fallback.
 *
 * TrustHosts does not enforce while runningUnitTests(), so these assert the
 * host list the middleware would install rather than driving a request.
 */
function magnaTrustedHostPatterns(): array
{
    return array_values(array_filter(app(TrustHosts::class)->hosts()));
}

it('trusts any host while the site is not installed', function (): void {
    config(['magna.installed_override' => false]);

    expect(magnaTrustedHostPatterns())->toContain('^.+$');
});

it('stops trusting arbitrary hosts once installed', function (): void {
    config([
        'magna.installed_override' => true,
        'app.url' => 'https://example.test',
        'magna.security.trusted_hosts' => '',
    ]);

    $patterns = magnaTrustedHostPatterns();

    expect($patterns)->not->toContain('^.+$')
        ->and($patterns)->toContain('^(.+\.)?example\.test$');
});

it('keeps honouring the extra trusted-host list once installed', function (): void {
    config([
        'magna.installed_override' => true,
        'app.url' => 'https://example.test',
        'magna.security.trusted_hosts' => 'alias.test, second.test',
    ]);

    expect(magnaTrustedHostPatterns())
        ->toContain('alias.test')
        ->toContain('second.test');
});

it('answers a refused host with a page naming the host and the fix', function (): void {
    config(['app.url' => 'https://example.test']);

    $request = Request::create('/', server: ['HTTP_HOST' => 'spoofed.example.com']);

    $response = app(ExceptionHandler::class)->render($request, new BadRequestHttpException(
        'Bad request.',
        new SuspiciousOperationException('Untrusted Host'),
    ));

    expect($response->getStatusCode())->toBe(400);

    $body = (string) $response->getContent();

    expect($body)
        ->toContain('spoofed.example.com')
        ->toContain('https://example.test')
        ->toContain('MAGNA_TRUSTED_HOSTS');
});

it('escapes the refused host rather than reflecting it as markup', function (): void {
    $request = Request::create('/', server: ['HTTP_HOST' => '<script>alert(1)</script>']);

    $body = (string) app(ExceptionHandler::class)->render($request, new BadRequestHttpException(
        'Bad request.',
        new SuspiciousOperationException('Untrusted Host'),
    ))->getContent();

    expect($body)
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;');
});

it('answers an API client with JSON instead of the diagnostic page', function (): void {
    $request = Request::create('/api/entries', server: ['HTTP_ACCEPT' => 'application/json']);

    $response = app(ExceptionHandler::class)->render($request, new BadRequestHttpException(
        'Bad request.',
        new SuspiciousOperationException('Untrusted Host'),
    ));

    expect($response->getStatusCode())->toBe(400)
        ->and(json_decode((string) $response->getContent(), true))
        ->toBe(['message' => 'Bad hostname provided.']);
});

it('leaves unrelated bad requests to the default renderer', function (): void {
    // Debug off, or the trace page quotes bootstrap/app.php — whose comments
    // name the same settings this page does — and the assertion reads as a
    // match when nothing matched.
    config(['app.debug' => false]);

    $body = (string) app(ExceptionHandler::class)
        ->render(Request::create('/'), new BadRequestHttpException('Malformed payload.'))
        ->getContent();

    expect($body)->not->toContain('not one this site answers to');
});
