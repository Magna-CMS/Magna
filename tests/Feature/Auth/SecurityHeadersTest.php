<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use Magna\Auth\Http\Middleware\AdminCspMiddleware;

it('attaches security headers to web responses', function (): void {
    // "/" redirects guests to the panel login; assert on the 200 login page.
    $response = $this->get('/login');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Strict-Transport-Security');
});

it('attaches security headers to API responses', function (): void {
    $response = $this->getJson('/api/v1/tokens');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Strict-Transport-Security');
});

it('adds CSP when admin-csp middleware is applied', function (): void {
    // Manually hit a route with the admin CSP middleware applied inline
    Route::get('/_test_csp', function () {
        return 'ok';
    })->middleware('magna.admin-csp');

    $response = $this->get('/_test_csp');

    $response->assertHeader('Content-Security-Policy');
    $csp = $response->headers->get('Content-Security-Policy', '');
    expect($csp)->toContain("frame-ancestors 'none'");
    expect($csp)->toContain("default-src 'self'");
});

it('login page has security headers', function (): void {
    $this->get(route('auth.login'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('serves the admin panel itself under the CSP', function (): void {
    // The middleware existed for months attached to nothing - this pins it
    // to the real panel so it cannot silently fall off again.
    $csp = $this->get('/login')->headers->get('Content-Security-Policy', '');

    expect($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("form-action 'self'");
});

// The file uploader fetches its own object URLs to build previews and to feed
// its processing worker. connect-src 'self' does not cover blob:, so dropping
// blob: here makes every upload field hang on its spinner - no thumbnail for an
// existing file, and new uploads never finish processing.
it('allows blob: object URLs the file uploader depends on', function (): void {
    $csp = $this->get('/login')->headers->get('Content-Security-Policy', '');

    expect($csp)->toContain('connect-src \'self\' blob:')
        ->and($csp)->toContain('worker-src \'self\' blob:')
        ->and($csp)->toContain('img-src \'self\' data: blob:');
});

it('lets a response with its own policy keep it', function (): void {
    Route::get('/_test_own_csp', function () {
        return response('ok', 200, ['Content-Security-Policy' => "frame-ancestors 'self'"]);
    })->middleware(['web', AdminCspMiddleware::class]);

    $csp = $this->get('/_test_own_csp')->headers->get('Content-Security-Policy', '');

    expect($csp)->toBe("frame-ancestors 'self'");
});
