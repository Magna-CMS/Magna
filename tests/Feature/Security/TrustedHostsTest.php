<?php

declare(strict_types=1);

/**
 * Host and X-Forwarded-Host are client-supplied, and Laravel builds absolute
 * URLs — password-reset links first among them — from whatever they claim.
 * Without a TrustHosts registration a spoofed header put an attacker's
 * domain into a victim's reset email. The registration in bootstrap/app.php
 * bounds every request to APP_URL's host + subdomains plus the explicit
 * MAGNA_TRUSTED_HOSTS list; anything else is refused before a URL is built.
 *
 * TrustHosts skips enforcement while running unit tests, so the enforcement
 * case below drives the middleware directly through a subclass that opts in.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Magna\Auth\Http\Middleware\DenyManagementCrossOriginMiddleware;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function (): void {
    // setTrustedHosts is process-global; never leak it into other tests.
    Request::setTrustedHosts([]);
});

function enforcingTrustHosts(): TrustHosts
{
    return new class(app()) extends TrustHosts
    {
        protected function shouldSpecifyTrustedHosts(): bool
        {
            return true;
        }
    };
}

it('trusts APP_URL subdomains plus the configured extra hosts', function (): void {
    config(['app.url' => 'https://cms.example.com']);
    config(['magna.security.trusted_hosts' => 'panel.other.test, 192.168.1.8']);

    $patterns = enforcingTrustHosts()->hosts();

    expect($patterns)->toContain('panel.other.test')
        ->and($patterns)->toContain('192.168.1.8')
        ->and(implode(' ', array_filter($patterns)))->toContain('cms\.example\.com');
});

it('refuses a request whose Host is not a trusted one', function (): void {
    config(['app.url' => 'https://cms.example.com']);
    config(['magna.security.trusted_hosts' => '']);

    $request = Request::create('https://cms.example.com/forgot-password');
    $request->headers->set('Host', 'evil.attacker.test');

    enforcingTrustHosts()->handle($request, fn (Request $r): Response => new Response(''));

    // The refusal happens the moment anything asks for the host — which is
    // before any absolute URL (a reset link included) can be built from it.
    expect(fn (): string => $request->getHost())->toThrow(SuspiciousOperationException::class);
});

it('accepts a request on an explicitly trusted extra host', function (): void {
    config(['app.url' => 'https://cms.example.com']);
    config(['magna.security.trusted_hosts' => '192.168.1.8']);

    $request = Request::create('http://192.168.1.8/login');

    enforcingTrustHosts()->handle($request, fn (Request $r): Response => new Response(''));

    expect($request->getHost())->toBe('192.168.1.8');
});

it('keeps TrustHosts in the global middleware stack', function (): void {
    expect(app(Kernel::class)->getGlobalMiddleware())->toContain(TrustHosts::class);
});

// ── One origin notion: the request's own, everywhere ────────────────────────

it('allows a same-origin management call on a host that is not APP_URL', function (): void {
    // The multi-host regression: the panel on a second trusted address made
    // management calls that were same-origin to the browser but cross-origin
    // to the old APP_URL comparison, and 403'd on the very host the
    // administrator was using.
    config(['app.url' => 'https://cms.example.com']);

    $request = Request::create('http://192.168.1.8/api/v1/manage/entries/blog');
    $request->headers->set('Origin', 'http://192.168.1.8');

    $response = (new DenyManagementCrossOriginMiddleware)->handle($request, fn (Request $r): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(200);
});

it('still refuses a genuinely foreign origin', function (): void {
    config(['app.url' => 'https://cms.example.com']);

    $request = Request::create('https://cms.example.com/api/v1/manage/entries/blog');
    $request->headers->set('Origin', 'https://evil.attacker.test');

    $response = (new DenyManagementCrossOriginMiddleware)->handle($request, fn (Request $r): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(403);
});
