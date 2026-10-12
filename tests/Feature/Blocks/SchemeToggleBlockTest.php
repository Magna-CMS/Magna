<?php

declare(strict_types=1);

/**
 * The visitor's own light/dark switch.
 *
 * The page carries both readings of the palette in one stylesheet, so the
 * switch changes nothing about what was SERVED: it stamps `data-theme` on
 * the root, which the token rules already answer to. That is what keeps
 * the page cacheable — every visitor gets identical bytes and the choice
 * lives in their own browser.
 *
 * It arrives in two files, and which half is which matters: the view draws
 * the button and styles it, and the BEHAVIOUR — the script, and the icon
 * swap it drives — lives in `block-behaviour/scheme-toggle.blade.php`,
 * where a theme replacing the view cannot drop it.
 */

use Illuminate\Support\Facades\Blade;
use Magna\Blocks\BlockRegistry;
use Tests\TestCase;

uses(TestCase::class);

function renderSchemeToggle(array $data = []): string
{
    return Blade::render(
        (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Magna/Blocks/resources/views/blocks/scheme-toggle.blade.php',
        ),
        ['block' => ['data' => $data]],
    );
}

function schemeToggleBehaviour(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 3).'/src/Magna/Blocks/resources/views/block-behaviour/scheme-toggle.blade.php',
    );
}

it('is a block that can be dropped into a header', function (): void {
    $definition = app(BlockRegistry::class)->get('scheme-toggle');

    expect($definition)->not->toBeNull()
        ->and($definition->label)->toBe('Light / dark switch');
});

it('renders a real button, announced and focusable', function (): void {
    $html = renderSchemeToggle();

    // A <button>, not a div with a click handler: it has to be reachable
    // by keyboard and announced as the control it is.
    expect($html)->toContain('<button type="button"')
        ->and($html)->toContain('aria-label="Switch between light and dark"')
        ->and($html)->toContain('aria-pressed="false"');
});

it('stays hidden until the script that makes it work has run', function (): void {
    // A switch that cannot work without JavaScript should not be offered
    // to someone who has none.
    expect(renderSchemeToggle())->toContain('hidden');
});

it('writes the attribute the palette already answers to', function (): void {
    $behaviour = schemeToggleBehaviour();

    // Not a new mechanism: `data-theme` is the same attribute the token
    // rules use, so the switch needs no second palette to drive.
    expect($behaviour)->toContain("setAttribute('data-theme'")
        ->and($behaviour)->toContain('prefers-color-scheme: dark')
        ->and($behaviour)->toContain(':root[data-theme="dark"]');
});

it('keeps the behaviour out of the view a theme replaces', function (): void {
    // The bug: all of the above once lived in the view. A theme overriding
    // the view to restyle the button lost the un-hide, the click handler and
    // the stored choice, and the theme audit then refused it a <script> of
    // its own — so the honest repair was to reimplement core's behaviour in
    // the layout. What shipped meanwhile was a button that renders `hidden`
    // and is never un-hidden: invisible, not merely unstyled.
    $view = renderSchemeToggle();

    expect($view)->not->toContain('<script')
        ->and($view)->not->toContain("setAttribute('data-theme'");

    // What the view owes the behaviour instead: the hook it binds to, the
    // attribute it lifts, and the two spans it swaps. ThemeAuditor warns an
    // override that drops any of them.
    expect($view)->toContain('data-magna-scheme-toggle')
        ->and($view)->toContain('hidden>')
        ->and($view)->toContain('magna-scheme-toggle__light')
        ->and($view)->toContain('magna-scheme-toggle__dark');
});

it('lets a theme outbid the icon swap without escalating selectors', function (): void {
    // The swap ships unconditionally now, which it did not before: when a
    // theme overrode the view, core's @once block never ran at all. So every
    // rule is wrapped in :where() and carries zero specificity, and a plain
    // class rule in a theme beats it. Otherwise fixing the dead control
    // would have broken the look of every theme that restyles the switch.
    $behaviour = schemeToggleBehaviour();

    foreach (['display: none', 'display: inline-flex'] as $declaration) {
        expect($behaviour)->toContain($declaration);
    }

    // Every selector in the stylesheet goes through :where(). One that did
    // not would carry real specificity and quietly outrank the theme.
    $style = (string) substr(
        $behaviour,
        (int) strpos($behaviour, '<style>'),
        (int) strpos($behaviour, '</style>') - (int) strpos($behaviour, '<style>'),
    );

    $bare = [];
    $wrapped = 0;
    foreach (explode("\n", $style) as $line) {
        $line = trim($line);
        if (! str_contains($line, '{') || str_starts_with($line, '@') || str_starts_with($line, '/*')) {
            continue;
        }

        $selector = trim(strstr($line, '{', true) ?: '');
        if ($selector === '') {
            continue;
        }

        if (str_starts_with($selector, ':where(')) {
            $wrapped++;
        } else {
            $bare[] = $selector;
        }
    }

    // $wrapped guards the guard: an empty scan would pass this vacuously.
    expect($bare)->toBe([])
        ->and($wrapped)->toBeGreaterThan(5);
});

it('wires a button once, however often it is handed one', function (): void {
    // A builder fragment re-render can hand the same button back; binding
    // twice would flip the scheme and flip it straight back.
    expect(schemeToggleBehaviour())->toContain('magnaSchemeWired');
});

it('escapes a label rather than letting it close the attribute', function (): void {
    $html = renderSchemeToggle(['label' => '"><script>alert(1)</script>']);

    expect(str_contains($html, '<script>alert(1)</script>'))->toBeFalse();
});

it('falls back to a usable label when given an empty one', function (): void {
    // A switch with no accessible name is a switch a screen reader calls
    // "button", which is no name at all.
    expect(renderSchemeToggle(['label' => '   ']))
        ->toContain('aria-label="Switch between light and dark"');
});

it('sizes the icons it draws', function (): void {
    expect(renderSchemeToggle(['size' => 'lg']))->toContain('width="26"')
        ->and(renderSchemeToggle(['size' => 'sm']))->toContain('width="16"')
        // An unknown size is the middle one, not a broken one.
        ->and(renderSchemeToggle(['size' => 'enormous']))->toContain('width="20"');
});

it('renames the button per state only where a view opts in', function (): void {
    $behaviour = (string) file_get_contents(
        dirname(__DIR__, 3).'/src/Magna/Blocks/resources/views/block-behaviour/scheme-toggle.blade.php'
    );

    // Both attributes, read together: one alone must change nothing, so a
    // half-declared override cannot strand a button mid-rename.
    expect($behaviour)->toContain('data-magna-label-to-light')
        ->toContain('data-magna-label-to-dark')
        ->toContain('if (toLight && toDark)');

    // And the default markup declares neither — the author's configured
    // label, which may be localised, is only ever replaced by a view that
    // asked for exactly that.
    expect(renderSchemeToggle())
        ->not->toContain('data-magna-label-to-light')
        ->not->toContain('data-magna-label-to-dark');
});
