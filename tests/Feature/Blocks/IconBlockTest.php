<?php

declare(strict_types=1);

/**
 * The icon block (docs/magna-pages/13-BUILDER-CHROME-AND-CANVAS.md §4 F7).
 *
 * The block stores an icon NAME and nothing else that could become markup.
 * These tests hold that line at the rendering end, where the registry's
 * guarantee either survives contact with a document or does not.
 */

use Illuminate\Support\Facades\Blade;
use Magna\Blocks\BlockRegistry;
use Tests\TestCase;

uses(TestCase::class);

function renderIconBlock(array $data): string
{
    $html = Blade::render(
        (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Magna/Blocks/resources/views/blocks/icon.blade.php',
        ),
        ['block' => ['data' => $data]],
    );

    // Blade brackets every @if with conditional-comment markers. They are
    // not output the block chose, so they are not what these assert on.
    return trim((string) preg_replace('/<!--\[if (BLOCK|ENDBLOCK)\]><!\[endif\]-->/', '', $html));
}

it('is offered as a block anyone can add', function (): void {
    $definition = app(BlockRegistry::class)->get('icon');

    expect($definition)->not->toBeNull()
        ->and($definition->label)->toBe('Icon')
        // No colour or alignment field: the Style tab already does both,
        // and a duplicate control is a place for them to disagree.
        ->and(array_map(fn ($field) => $field->handle, $definition->fields))
        ->toBe(['name', 'size', 'url', 'target', 'label']);
});

it('draws the named icon at an intrinsic size', function (): void {
    $html = renderIconBlock(['name' => 'core:star', 'size' => 'lg']);

    // Width and height attributes, because core block views ship no CSS:
    // a viewBox alone lays an SVG out at 300x150 and looks broken.
    expect($html)->toContain('<svg')
        ->and($html)->toContain('width="40"')
        ->and($html)->toContain('stroke="currentColor"')
        ->and($html)->toContain('magna-block--icon');
});

it('renders nothing at all for an icon that does not exist', function (): void {
    // Not a placeholder, not an error — a document naming an icon this
    // install does not have simply has no icon there.
    expect(renderIconBlock(['name' => 'core:not-real']))->toBe('')
        ->and(renderIconBlock(['name' => '']))->toBe('');
});

it('cannot be talked into rendering markup from the document', function (): void {
    // The field is a name. Every one of these is a name the registry does
    // not know, so every one of them draws nothing.
    foreach ([
        '<script>alert(1)</script>',
        'core:star"><script>alert(1)</script>',
        '../../etc/passwd',
    ] as $attempt) {
        $html = renderIconBlock(['name' => $attempt]);

        expect(str_contains($html, '<script>'))->toBeFalse()
            ->and($html)->toBe('');
    }
});

it('sanitises the link and marks a new tab safe', function (): void {
    $html = renderIconBlock([
        'name' => 'core:star',
        'url' => 'javascript:alert(1)',
        'target' => '_blank',
    ]);

    expect(str_contains($html, 'javascript:'))->toBeFalse();

    $safe = renderIconBlock([
        'name' => 'core:star',
        'url' => 'https://example.test',
        'target' => '_blank',
    ]);

    expect($safe)->toContain('href="https://example.test"')
        ->and($safe)->toContain('rel="noopener noreferrer"');
});

it('announces an icon that carries meaning and hides one that does not', function (): void {
    expect(renderIconBlock(['name' => 'core:star']))->toContain('aria-hidden="true"');

    $labelled = renderIconBlock(['name' => 'core:star', 'label' => 'Rated five stars']);
    expect($labelled)->toContain('aria-label="Rated five stars"')
        ->and($labelled)->toContain('role="img"');
});
