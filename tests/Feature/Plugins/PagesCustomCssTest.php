<?php

declare(strict_types=1);

/**
 * The declaration sanitizer — the one place this plugin decides what may
 * appear in a CSS value.
 *
 * It is unescaped output by construction, so it is load-bearing, and it
 * gained an exception when page backgrounds landed: `url("…")` in exactly
 * the shape the style descriptors build themselves. This file exists
 * because widening a sanitizer is the kind of change that deserves its own
 * adversarial tests rather than the coverage it gets in passing.
 */

use Magna\Pages\Render\CustomCss;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

beforeEach(function (): void {
    skipWithoutDevPlugin('magna/pages');
});

it('keeps an ordinary declaration', function (): void {
    expect(CustomCss::sanitize('color:#fff;padding-top:2rem'))
        ->toBe('color:#fff;padding-top:2rem');
});

it('allows a design token, which is the system’s own currency', function (): void {
    expect(CustomCss::sanitize('color:var(--color-primary)'))
        ->toBe('color:var(--color-primary)');
});

it('allows the one url shape the descriptors build, and no other', function (): void {
    expect(CustomCss::sanitize('background-image:url("/media/hero.jpg")'))
        ->toBe('background-image:url("/media/hero.jpg")');
    expect(CustomCss::sanitize('background-image:url("https://cdn.test/a.png")'))
        ->toBe('background-image:url("https://cdn.test/a.png")');
});

it('refuses every other way of writing a url', function (): void {
    // Unquoted, single-quoted, scheme-relative, and the schemes that make
    // url() an exfiltration channel in the first place.
    foreach ([
        'background-image:url(/media/hero.jpg)',
        "background-image:url('/media/hero.jpg')",
        'background-image:url("//evil.test/x.png")',
        'background-image:url("javascript:alert(1)")',
        'background-image:url("data:image/svg+xml;base64,AAAA")',
        'background-image:url("ftp://evil.test/x")',
        // Valid CSS, but not a shape the descriptors build — and the
        // value of a narrow exception is that it stays narrow.
        'background-image:url("/a.png"), url("/b.png")',
        'background-image:red url("/a.png")',
    ] as $attempt) {
        expect(CustomCss::sanitize($attempt))->toBe('', "accepted: {$attempt}");
    }
});

it('never lets a url carry an injection past the sanitizer', function (): void {
    // Declarations are split on `;` before anything else, so an attempt to
    // close the rule becomes a second declaration and is dropped on its own
    // merits. What matters is not that nothing survives — a legitimate
    // first half may — but that nothing hostile does.
    foreach ([
        'background-image:url("/a.png");}body{display:none',
        'background-image:url("/a.png\\")",color:red',
        'background-image:url("/a.png") ; background:url(x)',
        'background-image:url("/a b.png")',
        'background-image:url("/a.png"),url(evil)',
        'background-image:url("/a.png");background-image:url("//evil.test/beacon")',
    ] as $attempt) {
        $css = CustomCss::sanitize($attempt);

        expect(str_contains($css, '{'))->toBeFalse("brace survived: {$attempt}")
            ->and(str_contains($css, '}'))->toBeFalse("brace survived: {$attempt}")
            ->and(str_contains($css, 'display:none'))->toBeFalse("payload survived: {$attempt}")
            ->and(str_contains($css, 'url(x'))->toBeFalse("second url survived: {$attempt}")
            ->and(str_contains($css, 'url(evil'))->toBeFalse("second url survived: {$attempt}")
            ->and(str_contains($css, 'evil.test'))->toBeFalse("beacon survived: {$attempt}")
            ->and(substr_count($css, 'url('))->toBeLessThanOrEqual(1);
    }
});

it('still refuses functions the exception does not name', function (): void {
    foreach ([
        'width:calc(100% - 2rem)',
        'background:image-set("/a.png" 1x)',
        'transform:translateX(10px)',
        'color:rgb(0 0 0)',
        'background:element(#x)',
    ] as $attempt) {
        expect(CustomCss::sanitize($attempt))->toBe('', "accepted: {$attempt}");
    }
});

it('still refuses braces, at-rules, comments, escapes and control characters', function (): void {
    foreach ([
        'color:red}body{display:none',
        'color:red;@import "evil.css"',
        'color:red/*x*/',
        'color:\\72 ed',
        "color:re\x00d",
        'color:<script>',
        "content:'x'",
        'content:"x"',
    ] as $attempt) {
        $css = CustomCss::sanitize($attempt);

        expect(str_contains($css, 'body{'))->toBeFalse()
            ->and(str_contains($css, '@import'))->toBeFalse()
            ->and(str_contains($css, '<'))->toBeFalse();
    }
});

it('drops a property that is not a property', function (): void {
    expect(CustomCss::sanitize('col or:red'))->toBe('')
        ->and(CustomCss::sanitize('4color:red'))->toBe('')
        ->and(CustomCss::sanitize('color'))->toBe('');
});

it('refuses an oversized declaration list outright', function (): void {
    expect(CustomCss::sanitize('color:red;'.str_repeat('padding:1px;', 400)))->toBe('');
});
