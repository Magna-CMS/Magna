<?php

declare(strict_types=1);

/**
 * Styling the blocks of one pattern, and not the band around them.
 *
 * A section's `cssClass` was the only hook a theme had, and a section is a
 * whole band. When one band holds several patterns its class list holds all
 * of them at once — `class="mock mock-sync split wide-left steps"` — so a
 * rule written for one pattern reaches another pattern's blocks, and no
 * section-scoped selector can tell them apart without leaning on a
 * structural accident. A port of a real site hit that three times in one
 * page set: keycap rules restyled unrelated steps, and hiding one band's
 * icon hid a graphic in another.
 *
 * A class on the CONTAINER is the scope those rules wanted — it reaches the
 * blocks it holds and nothing else. These assertions pin two things: the
 * class reaches the element, and a container that names none emits exactly
 * the markup it emitted before.
 */

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class);

function renderContainerWithClasses(array $data, string $children = '<p>inner</p>'): string
{
    return Blade::render(
        (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Magna/Blocks/resources/views/blocks/container.blade.php',
        ),
        ['block' => ['data' => $data], 'childrenHtml' => $children],
    );
}

it('puts the theme classes on the container itself', function (): void {
    $html = renderContainerWithClasses(['tag' => 'div', 'cssClass' => 'mock mock-sync']);

    expect($html)->toContain('class="magna-block magna-block--container magna-container mock mock-sync"');
});

it('keeps the library classes, so a theme adds to them rather than replacing them', function (): void {
    // The hook is additive: a container that an author styled is still a
    // container to every rule the library itself ships.
    $html = renderContainerWithClasses(['tag' => 'section', 'cssClass' => 'keycaps']);

    expect($html)->toContain('<section class="magna-block magna-block--container magna-container keycaps">');
});

it('changes nothing for a container that names no classes', function (): void {
    // Three markup assertions elsewhere in the suite compare this opening
    // tag character for character. Saying nothing must emit what it always
    // emitted.
    expect(renderContainerWithClasses(['tag' => 'div']))
        ->toContain('<div class="magna-block magna-block--container magna-container">');

    foreach (['', '   ', null] as $empty) {
        expect(renderContainerWithClasses(['tag' => 'div', 'cssClass' => $empty]))
            ->toContain('<div class="magna-block magna-block--container magna-container">');
    }
});

it('still marks an empty container empty, with the author classes after the marker', function (): void {
    $html = renderContainerWithClasses(['tag' => 'div', 'cssClass' => 'steps'], '');

    expect($html)->toContain('magna-container--empty steps');
});

it('drops a token that would have to be escaped to be safe', function (): void {
    // A quote is the one character that could end the attribute early. It is
    // dropped rather than escaped: `&quot;` in a class list is safe markup
    // that styles nothing, and the token it came from was never a class.
    $html = renderContainerWithClasses(['tag' => 'div', 'cssClass' => 'fine x" onmouseover="alert(1)']);

    expect($html)->toContain('class="magna-block magna-block--container magna-container fine"')
        ->and($html)->not->toContain('onmouseover')
        ->and($html)->not->toContain('&quot;');
});

it('keeps the characters real class names use', function (): void {
    // BEM underscores and Tailwind's colons, slashes and brackets are not a
    // security question, and a filter that ate them would make the field
    // useless to the themes most likely to want it.
    $html = renderContainerWithClasses([
        'tag' => 'div',
        'cssClass' => 'magna-features__icon md:flex w-1/2 p-[3px]',
    ]);

    expect($html)->toContain('magna-container magna-features__icon md:flex w-1/2 p-[3px]"');
});

it('normalises the whitespace an author actually types', function (): void {
    $html = renderContainerWithClasses(['tag' => 'div', 'cssClass' => "  lang   mii\t tint\n"]);

    expect($html)->toContain('magna-container lang mii tint"');
});

it('is independent of the name and role fields', function (): void {
    $html = renderContainerWithClasses([
        'tag' => 'figure', 'role' => 'img', 'label' => 'A till', 'cssClass' => 'pos',
    ]);

    expect($html)->toContain('<figure class="magna-block magna-block--container magna-container pos" role="img" aria-label="A till">');
});
