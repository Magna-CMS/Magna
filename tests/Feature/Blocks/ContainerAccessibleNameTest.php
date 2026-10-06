<?php

declare(strict_types=1);

/**
 * Naming a composite.
 *
 * A container is usually a box around other things, and then it should
 * announce nothing of its own. But some composites ARE the thing — a device
 * mockup or a diagram drawn out of text blocks reads as one picture and
 * announces as a pile of fragments. Nothing in the library could put a name
 * on an element that holds children, so eleven such composites on one site had
 * no accessible name at all.
 *
 * Both new fields are fixed lists or escaped text: a document can never put an
 * arbitrary role or raw markup into the wrapper.
 */

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class);

function renderContainer(array $data, string $children = '<p>inner</p>'): string
{
    return Blade::render(
        (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Magna/Blocks/resources/views/blocks/container.blade.php',
        ),
        ['block' => ['data' => $data], 'childrenHtml' => $children],
    );
}

it('names the container when an author gives it one', function (): void {
    $html = renderContainer(['tag' => 'div', 'role' => 'img', 'label' => 'A card terminal showing a paid receipt']);

    expect($html)->toContain('role="img"')
        ->and($html)->toContain('aria-label="A card terminal showing a paid receipt"');
});

it('stays silent when the author said nothing', function (): void {
    $html = renderContainer(['tag' => 'div']);

    expect($html)->not->toContain('role=')
        ->and($html)->not->toContain('aria-label=');
});

it('refuses a role that is not on the list', function (): void {
    // Same treatment the tag already had: a hand-edited document cannot put
    // an arbitrary ARIA role into the page.
    $html = renderContainer(['tag' => 'div', 'role' => 'presentation button', 'label' => 'Named']);

    expect($html)->not->toContain('role=')
        ->and($html)->toContain('aria-label="Named"');
});

it('escapes the name rather than trusting it', function (): void {
    $html = renderContainer(['tag' => 'div', 'role' => 'img', 'label' => 'Receipt " onmouseover="x']);

    expect($html)->not->toContain('onmouseover="x"')
        ->and($html)->toContain('&quot;');
});

it('offers figure and nav, which the sanitizer already allows', function (): void {
    expect(renderContainer(['tag' => 'figure']))->toContain('<figure')
        ->and(renderContainer(['tag' => 'nav']))->toContain('<nav');
});

it('still falls back to a div for an unknown element', function (): void {
    expect(renderContainer(['tag' => 'script']))->toContain('<div');
});

it('ignores a blank name', function (): void {
    $html = renderContainer(['tag' => 'div', 'label' => '   ']);

    expect($html)->not->toContain('aria-label');
});
