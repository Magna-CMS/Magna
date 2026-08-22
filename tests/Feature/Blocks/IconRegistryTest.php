<?php

declare(strict_types=1);

/**
 * The icon vocabulary (docs/magna-pages/13-BUILDER-CHROME-AND-CANVAS.md §3B).
 *
 * The rule this file protects: a document stores an icon NAME, never
 * markup, and the registry is the only thing that can turn a name into
 * SVG. Everything below is a way of asserting that no path exists from a
 * stored value — or from a plugin's set file — to arbitrary markup on a
 * rendered page.
 */

use Magna\Blocks\Icons\IconRegistry;
use Tests\TestCase;

// Feature/Blocks names its base class per file; without this the app never
// boots and app() hands back a reflection-built registry with nothing in it.
uses(TestCase::class);

it('ships a core set that renders as themeable geometry', function (): void {
    $registry = app(IconRegistry::class);

    expect($registry->count())->toBeGreaterThan(20)
        ->and($registry->has('core:star'))->toBeTrue();

    $svg = $registry->svg('core:star');

    // currentColor and no fill: one icon works on any background, in
    // either colour scheme, without a second copy of it existing.
    expect($svg)->toContain('stroke="currentColor"')
        ->and($svg)->toContain('viewBox="0 0 24 24"')
        ->and($svg)->toContain('fill="none"');
});

it('draws nothing for a name it does not know', function (): void {
    $registry = app(IconRegistry::class);

    // The wall: an unknown name is not a guess and not an error page, it
    // is nothing. A document carrying a stale icon name loses the icon.
    expect($registry->svg('core:not-an-icon'))->toBeNull()
        ->and($registry->body('made-up'))->toBeNull()
        ->and($registry->has('core:not-an-icon'))->toBeFalse();
});

it('hides a decorative icon from assistive technology, and names one that carries meaning', function (): void {
    $registry = app(IconRegistry::class);

    // Beside a label an icon is decoration, and announcing it twice is
    // worse than not announcing it.
    expect($registry->svg('core:check'))->toContain('aria-hidden="true"')
        ->and($registry->svg('core:check'))->toContain('focusable="false"');

    $labelled = (string) $registry->svg('core:check', 'Included');
    expect($labelled)->toContain('role="img"')
        ->and($labelled)->toContain('aria-label="Included"')
        ->and(str_contains($labelled, 'aria-hidden'))->toBeFalse();
});

it('refuses an icon that is not geometry', function (): void {
    $registry = new IconRegistry;

    // Every one of these would be markup of the registrant's choosing on
    // every page that drew the icon. A plugin registers through this door
    // and pays the same inspection at it.
    $registry->register('evil:script', '<script>alert(1)</script>');
    $registry->register('evil:handler', '<path d="M0 0" onload="alert(1)"/>');
    $registry->register('evil:reference', '<use href="http://evil.test/x.svg#a"/>');
    $registry->register('evil:image', '<image href="data:image/svg+xml;base64,AAAA"/>');
    $registry->register('evil:style', '<path d="M0 0" style="behavior:url(x)"/>');
    $registry->register('evil:foreign', '<foreignObject><p>hi</p></foreignObject>');
    $registry->register('evil:doctype', '<!DOCTYPE svg><path d="M0 0"/>');
    $registry->register('evil:empty', '');

    expect($registry->count())->toBe(0);
});

it('refuses a name that is not a namespaced handle', function (): void {
    $registry = new IconRegistry;

    // Namespacing is what lets two sets both offer a "star" without one
    // silently winning, so a name without a set is not an icon.
    $registry->register('star', '<path d="M0 0"/>');
    $registry->register('Core:Star', '<path d="M0 0"/>');
    $registry->register('core:star extra', '<path d="M0 0"/>');
    $registry->register('a:b:c', '<path d="M0 0"/>');

    expect($registry->count())->toBe(0);

    $registry->register('vendor:star', '<path d="M0 0"/>');
    expect($registry->names())->toBe(['vendor:star']);
});

it('escapes a label rather than letting it close the tag', function (): void {
    $registry = new IconRegistry;
    $registry->register('core:dot', '<circle cx="12" cy="12" r="3"/>');

    $svg = $registry->svg('core:dot', '"><script>alert(1)</script>');

    expect(str_contains((string) $svg, '<script>'))->toBeFalse();
    expect($svg)->toContain('&quot;');
});

it('ignores a set file that is not one', function (): void {
    $registry = new IconRegistry;

    $registry->loadFromFile(__DIR__.'/does-not-exist.json');
    expect($registry->count())->toBe(0);

    $path = tempnam(sys_get_temp_dir(), 'icons').'.json';
    file_put_contents($path, 'not json at all');
    $registry->loadFromFile($path);
    expect($registry->count())->toBe(0);

    file_put_contents($path, json_encode(['icons' => ['star' => '<path d="M0 0"/>']]));
    $registry->loadFromFile($path);
    expect($registry->count())->toBe(0); // no set name, so no namespace

    file_put_contents($path, json_encode(['set' => 'kit', 'icons' => ['star' => '<path d="M0 0"/>']]));
    $registry->loadFromFile($path);
    expect($registry->names())->toBe(['kit:star']);

    @unlink($path);
});

it('keeps every shipped core icon inside the geometry allowlist', function (): void {
    // The set file is data, and data drifts. This is the assertion that a
    // future icon added by hand cannot smuggle anything in with it.
    $shipped = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/src/Magna/Blocks/resources/icons/core.json'),
        true,
    );

    expect($shipped['icons'])->toBeArray()->not->toBeEmpty();

    $registry = app(IconRegistry::class);
    foreach (array_keys($shipped['icons']) as $name) {
        expect($registry->has('core:'.$name))->toBeTrue("core:{$name} was rejected by the registry");
    }
});
