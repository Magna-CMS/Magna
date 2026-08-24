<?php

declare(strict_types=1);

/**
 * Table and File download — the two blocks the field model appeared to
 * rule out.
 *
 * A table is rows of cells, which is a repeater inside a repeater, and
 * BlockField refuses to nest one. Delimited text is what fits, and it is
 * also the better editor experience for the common case: a table usually
 * arrives from a spreadsheet, and tab-separated is what the clipboard
 * already holds.
 *
 * A file download needed the picker to offer something other than
 * pictures, which is what `accept` on a media field is for.
 */

use Illuminate\Support\Facades\Blade;
use Magna\Blocks\BlockField;
use Magna\Blocks\BlockRegistry;
use Tests\TestCase;

uses(TestCase::class);

function renderTable(array $data): string
{
    return Blade::render(
        (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Magna/Blocks/resources/views/blocks/table.blade.php',
        ),
        ['block' => ['data' => $data]],
    );
}

it('splits rows on the separator the block chose', function (): void {
    $html = renderTable([
        'rows' => "Region\tRevenue\nNorth\t120\nSouth\t90",
        'separator' => 'tab',
    ]);

    expect($html)->toContain('<th scope="col">Region</th>')
        ->and($html)->toContain('<td>North</td>')
        ->and($html)->toContain('<td>120</td>')
        // Two data rows, the header having been taken off the top.
        ->and(substr_count($html, '<tr>'))->toBe(3);
});

it('treats the first row as data when told to', function (): void {
    $html = renderTable(['rows' => "a|b\nc|d", 'separator' => 'pipe', 'header' => 'data']);

    expect($html)->not->toContain('<thead>')
        ->and($html)->toContain('<td>a</td>');
});

it('pads a ragged row instead of dropping it', function (): void {
    // A row with a missing cell is a typo in the paste. Swallowing the
    // whole row would hide it; an empty cell shows it.
    $html = renderTable(['rows' => "a|b|c\nd|e", 'separator' => 'pipe', 'header' => 'data']);

    expect(substr_count($html, '<tr>'))->toBe(2)
        ->and(substr_count($html, '<td>'))->toBe(6);
});

it('escapes what it was pasted', function (): void {
    $html = renderTable([
        'rows' => '<script>alert(1)</script>|ok',
        'separator' => 'pipe',
        'header' => 'data',
    ]);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('ignores blank lines and renders nothing for an empty table', function (): void {
    $html = renderTable(['rows' => "a|b\n\n\nc|d", 'separator' => 'pipe', 'header' => 'data']);
    expect(substr_count($html, '<tr>'))->toBe(2);

    expect(trim(renderTable(['rows' => "\n  \n"])))->toBe('');
});

it('falls back to a tab separator rather than trusting a stored value', function (): void {
    // A separator hand-edited into a document must not reach explode() as
    // an arbitrary string.
    $html = renderTable(['rows' => "a\tb", 'separator' => 'nonsense', 'header' => 'data']);

    expect($html)->toContain('<td>a</td>')
        ->and($html)->toContain('<td>b</td>');
});

it('asks the picker for files rather than pictures', function (): void {
    $definition = app(BlockRegistry::class)->get('file');

    expect($definition)->not->toBeNull()
        ->and($definition->label)->toBe('File download');

    $media = $definition->field('media_id');

    // Without this the picker lists images only, and a download block
    // could be inserted but never given a file — the same shape of bug as
    // a select with no options.
    expect($media?->accept)->toBe(BlockField::ACCEPT_ANY);
});

it('keeps every other media field on pictures', function (): void {
    // `accept` defaults to images so no existing block.json had to change
    // and no picker silently widened.
    $image = app(BlockRegistry::class)->get('image')?->field('media_id');
    $logo = app(BlockRegistry::class)->get('logo')?->field('media_id');

    expect($image?->accept)->toBe(BlockField::ACCEPT_IMAGE)
        ->and($logo?->accept)->toBe(BlockField::ACCEPT_IMAGE);
});

it('narrows to pictures when a block.json asks for something it does not know', function (): void {
    $field = BlockField::fromArray([
        'handle' => 'thing',
        'type' => 'media',
        'accept' => 'everything-please',
    ]);

    // A typo must not be the thing that starts offering an editor every
    // file on the site.
    expect($field->accept)->toBe(BlockField::ACCEPT_IMAGE);
});
