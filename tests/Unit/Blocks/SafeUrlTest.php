<?php

declare(strict_types=1);

/**
 * SafeUrl scheme allowlisting, including the browser's URL preprocessing.
 *
 * Browsers strip leading/trailing C0 controls and spaces from an href and
 * remove every tab/LF/CR before parsing its scheme (WHATWG URL spec), so
 * " javascript:x" and "java\tscript:x" execute even though parse_url()
 * reports no scheme for them. SafeUrl must judge — and return — the value
 * the browser will actually act on.
 */

use Magna\Blocks\Support\SafeUrl;

// ── Dangerous schemes are rejected ───────────────────────────────────────────

it('rejects a plain javascript: URL', function (): void {
    expect(SafeUrl::sanitize('javascript:alert(1)'))->toBe('#');
});

it('rejects an uppercase JAVASCRIPT: URL', function (): void {
    expect(SafeUrl::sanitize('JAVASCRIPT:alert(1)'))->toBe('#');
});

it('rejects javascript: hidden behind leading whitespace', function (): void {
    expect(SafeUrl::sanitize(' javascript:alert(1)'))->toBe('#')
        ->and(SafeUrl::sanitize('   javascript:alert(1)'))->toBe('#');
});

it('rejects a tab-obfuscated scheme', function (): void {
    expect(SafeUrl::sanitize("java\tscript:alert(1)"))->toBe('#')
        ->and(SafeUrl::sanitize("\tjavascript:alert(1)"))->toBe('#');
});

it('rejects a newline-obfuscated scheme', function (): void {
    expect(SafeUrl::sanitize("java\nscript:alert(1)"))->toBe('#')
        ->and(SafeUrl::sanitize("java\rscript:alert(1)"))->toBe('#')
        ->and(SafeUrl::sanitize("\njavascript:alert(1)"))->toBe('#');
});

it('rejects javascript: behind leading C0 control characters', function (): void {
    expect(SafeUrl::sanitize("\x01javascript:alert(1)"))->toBe('#')
        ->and(SafeUrl::sanitize("\x00javascript:alert(1)"))->toBe('#')
        ->and(SafeUrl::sanitize("\x1Fjavascript:alert(1)"))->toBe('#');
});

it('rejects mixed whitespace and control-character obfuscation', function (): void {
    expect(SafeUrl::sanitize(" \x00\tjava\nscri\rpt:alert(1)"))->toBe('#')
        ->and(SafeUrl::sanitize("\x02 \tJaVa\nScRiPt:alert(document.cookie)"))->toBe('#');
});

it('rejects data: and vbscript: URLs, obfuscated or not', function (): void {
    expect(SafeUrl::sanitize('data:text/html,<script>alert(1)</script>'))->toBe('#')
        ->and(SafeUrl::sanitize(" data\t:text/html,x"))->toBe('#')
        ->and(SafeUrl::sanitize('vbscript:msgbox(1)'))->toBe('#')
        ->and(SafeUrl::sanitize("\tvbscript:msgbox(1)"))->toBe('#');
});

it('rejects other unknown schemes', function (): void {
    expect(SafeUrl::sanitize('file:///etc/passwd'))->toBe('#')
        ->and(SafeUrl::sanitize('ftp://example.com'))->toBe('#');
});

// ── Legitimate URLs pass through ─────────────────────────────────────────────

it('passes https URLs through unchanged', function (): void {
    expect(SafeUrl::sanitize('https://example.com/a?b=c#d'))->toBe('https://example.com/a?b=c#d');
});

it('passes http URLs through unchanged', function (): void {
    expect(SafeUrl::sanitize('http://example.com'))->toBe('http://example.com');
});

it('passes mailto and tel URLs through unchanged', function (): void {
    expect(SafeUrl::sanitize('mailto:hi@example.com'))->toBe('mailto:hi@example.com')
        ->and(SafeUrl::sanitize('tel:+15551234567'))->toBe('tel:+15551234567');
});

it('passes relative URLs through unchanged', function (): void {
    expect(SafeUrl::sanitize('/about'))->toBe('/about')
        ->and(SafeUrl::sanitize('./relative'))->toBe('./relative')
        ->and(SafeUrl::sanitize('#section'))->toBe('#section')
        ->and(SafeUrl::sanitize('?page=2'))->toBe('?page=2')
        ->and(SafeUrl::sanitize('docs/intro'))->toBe('docs/intro');
});

it('normalises stray whitespace out of an otherwise safe URL', function (): void {
    // The browser removes the tab/newline itself; returning the same
    // cleaned value keeps the checked scheme and the acted-on scheme equal.
    expect(SafeUrl::sanitize(" https://example.com/pa\tth "))->toBe('https://example.com/path')
        ->and(SafeUrl::sanitize("/abo\nut"))->toBe('/about');
});

// ── Fallback behaviour ───────────────────────────────────────────────────────

it('falls back for non-string, empty, and whitespace-only values', function (): void {
    expect(SafeUrl::sanitize(null))->toBe('#')
        ->and(SafeUrl::sanitize(42))->toBe('#')
        ->and(SafeUrl::sanitize([]))->toBe('#')
        ->and(SafeUrl::sanitize(''))->toBe('#')
        ->and(SafeUrl::sanitize(" \t\n"))->toBe('#');
});

it('honours a custom fallback', function (): void {
    expect(SafeUrl::sanitize('javascript:alert(1)', '/'))->toBe('/')
        ->and(SafeUrl::sanitize(' javascript:alert(1)', '/'))->toBe('/');
});
