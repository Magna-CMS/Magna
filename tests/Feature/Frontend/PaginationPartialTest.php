<?php

declare(strict_types=1);

use Magna\Frontend\Pagination;
use Tests\TestCase;

uses(TestCase::class);

it('renders the frozen class contract themes style', function (): void {
    $html = view('magna::partials.pagination', ['pagination' => new Pagination(2, 10, 35, '/blog')])->render();

    expect($html)->toContain('<nav class="magna-pagination"')
        ->and($html)->toContain('class="magna-pagination__prev" href="/blog" rel="prev"')
        ->and($html)->toContain('class="magna-pagination__next" href="/blog?page=3" rel="next"')
        ->and($html)->toContain('<span class="magna-pagination__status">Page 2 of 4</span>');
});

it('renders nothing for a listing that fits on one page', function (): void {
    $html = view('magna::partials.pagination', ['pagination' => new Pagination(1, 10, 7, '/blog')])->render();

    expect(trim($html))->toBe('');
});

it('escapes the path it links to', function (): void {
    $html = view('magna::partials.pagination', ['pagination' => new Pagination(1, 10, 35, '/blog"><script>x</script>')])->render();

    expect($html)->not->toContain('<script>x</script>');
});
