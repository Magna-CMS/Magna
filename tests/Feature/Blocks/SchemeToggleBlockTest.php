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
    $html = renderSchemeToggle();

    // Not a new mechanism: `data-theme` is the same attribute the token
    // rules use, so the switch needs no second palette to drive.
    expect($html)->toContain("setAttribute('data-theme'")
        ->and($html)->toContain('prefers-color-scheme: dark')
        ->and($html)->toContain(':root[data-theme="dark"]');
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
