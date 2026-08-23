<?php

declare(strict_types=1);

/**
 * Quote, Code and Callout — the prose blocks a page needs and core did not
 * have.
 *
 * They live in core rather than in a plugin because none of them is about
 * anything: a quotation, a snippet and a note are what long-form content is
 * made of, and a site should not have to install a blog to set a paragraph
 * apart. All three render escaped, which for the code block is the whole
 * point — it is the one field guaranteed to contain angle brackets.
 */

use Illuminate\Support\Facades\Blade;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\Icons\IconRegistry;
use Tests\TestCase;

uses(TestCase::class);

function renderProseBlock(string $handle, array $data): string
{
    return Blade::render(
        (string) file_get_contents(
            dirname(__DIR__, 3)."/src/Magna/Blocks/resources/views/blocks/{$handle}.blade.php",
        ),
        ['block' => ['data' => $data]],
    );
}

it('registers each prose block with an icon the registry knows', function (string $handle, string $label): void {
    $definition = app(BlockRegistry::class)->get($handle);

    expect($definition)->not->toBeNull()
        ->and($definition->label)->toBe($label)
        // A document stores an icon NAME, so a block naming one the registry
        // cannot draw ships a hole in the Add panel.
        ->and(app(IconRegistry::class)->names())->toContain($definition->icon);
})->with([
    ['quote', 'Quote'],
    ['code', 'Code'],
    ['callout', 'Callout'],
]);

it('escapes a code snippet rather than running it', function (): void {
    $html = renderProseBlock('code', [
        'code' => '<script>alert(1)</script>',
        'language' => 'javascript',
    ]);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('class="language-javascript"');
});

it('refuses a language it does not publish', function (): void {
    // A stored value from an older schema, or one hand-edited into a
    // document, must not be able to write arbitrary text into a class.
    $html = renderProseBlock('code', ['code' => 'echo 1;', 'language' => '" onload="evil()']);

    expect($html)->toContain('class="language-plain"')
        ->and($html)->not->toContain('onload');
});

it('renders a quote as a figure with its attribution', function (): void {
    $html = renderProseBlock('quote', [
        'quote' => 'A design that needs explaining is not finished.',
        'attribution' => 'Someone',
        'source' => 'A book',
    ]);

    expect($html)->toContain('<blockquote')
        ->and($html)->toContain('A design that needs explaining is not finished.')
        ->and($html)->toContain('<cite')
        ->and($html)->toContain('Someone');
});

it('leaves the attribution out entirely when there is none', function (): void {
    $html = renderProseBlock('quote', ['quote' => 'Unattributed.']);

    // An empty <figcaption> is a box a theme still draws — absent means
    // absent, not present and blank.
    expect($html)->toContain('Unattributed.')
        ->and($html)->not->toContain('figcaption');
});

it('marks a callout with its tone and refuses an unknown one', function (): void {
    $info = renderProseBlock('callout', ['body' => 'Note this.']);
    expect($info)->toContain('magna-callout--info')
        ->and($info)->toContain('role="note"');

    $warning = renderProseBlock('callout', ['body' => 'Careful.', 'tone' => 'warning']);
    expect($warning)->toContain('magna-callout--warning');

    $forged = renderProseBlock('callout', ['body' => 'Hm.', 'tone' => 'evil" onmouseover="x']);
    expect($forged)->toContain('magna-callout--info')
        ->and($forged)->not->toContain('onmouseover');
});

it('renders nothing at all when its required field is empty', function (string $handle): void {
    // A block dropped in and not yet filled must leave no empty shell on
    // the published page — the builder shows a placeholder, the page does
    // not.
    expect(trim(renderProseBlock($handle, [])))->toBe('');
})->with(['quote', 'code', 'callout']);
