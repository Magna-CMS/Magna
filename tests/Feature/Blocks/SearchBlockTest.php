<?php

declare(strict_types=1);

/**
 * The search block.
 *
 * Core owns no search results page, so this element is a FORM and where it
 * leads is the site's business — the same conclusion the account element
 * reached. A box that submits into a 404 is worse than no box, so it draws
 * nothing until an editor says where results live.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function renderSearch(array $data = []): string
{
    return trim((string) preg_replace(
        '/<!--\[if (BLOCK|ENDBLOCK)\]><!\[endif\]-->/',
        '',
        Blade::render(
            (string) file_get_contents(
                dirname(__DIR__, 3).'/src/Magna/Blocks/resources/views/blocks/search.blade.php',
            ),
            ['block' => ['id' => 'blk-1', 'data' => $data]],
        ),
    ));
}

it('draws nothing until it knows where results live', function (): void {
    expect(renderSearch())->toBe('')
        ->and(renderSearch(['action' => '']))->toBe('');
});

it('submits by GET, so a search can be linked and gone back to', function (): void {
    $html = renderSearch(['action' => '/search']);

    expect($html)->toContain('method="get"')
        ->and($html)->toContain('action="/search"')
        ->and($html)->toContain('role="search"');
});

it('names the field for a screen reader', function (): void {
    // A magnifying glass with no name announces as "button".
    $html = renderSearch(['action' => '/search']);

    expect($html)->toContain('for="magna-search-blk-1"')
        ->and($html)->toContain('Search this site');
});

it('refuses a query parameter that is not a name', function (): void {
    foreach (['q"><script>', 'a b', '1st', ''] as $attempt) {
        $html = renderSearch(['action' => '/search', 'param' => $attempt]);

        expect($html)->toContain('name="q"')
            ->and(str_contains($html, '<script>'))->toBeFalse();
    }

    // And keeps one that is.
    expect(renderSearch(['action' => '/search', 'param' => 'query']))->toContain('name="query"');
});

it('sanitises where it submits', function (): void {
    expect(str_contains(renderSearch(['action' => 'javascript:alert(1)']), 'javascript:'))->toBeFalse();
});
