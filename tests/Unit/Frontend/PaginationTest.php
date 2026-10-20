<?php

declare(strict_types=1);

use Magna\Frontend\Pagination;
use Magna\Frontend\RenderContext;

it('keeps page one at the bare path so a listing has one canonical url', function (): void {
    $pagination = new Pagination(2, 10, 35, '/blog');

    expect($pagination->urlFor(1))->toBe('/blog')
        ->and($pagination->previousUrl())->toBe('/blog')
        ->and($pagination->nextUrl())->toBe('/blog?page=3')
        ->and($pagination->lastPage)->toBe(4)
        ->and($pagination->offset())->toBe(10);
});

it('has no previous link on the first page and no next link on the last', function (): void {
    expect((new Pagination(1, 10, 35, '/blog'))->previousUrl())->toBeNull()
        ->and((new Pagination(4, 10, 35, '/blog'))->nextUrl())->toBeNull();
});

it('treats an empty listing as a single page', function (): void {
    $pagination = new Pagination(1, 10, 0, '/blog');

    expect($pagination->lastPage)->toBe(1)
        ->and($pagination->hasPages())->toBeFalse()
        ->and($pagination->isOutOfRange())->toBeFalse();
});

it('knows a page past the end is out of range', function (): void {
    $pagination = new Pagination(5, 10, 35, '/blog');

    expect($pagination->isOutOfRange())->toBeTrue()
        ->and($pagination->hasPrevious())->toBeFalse();
});

it('never counts more pages than a listing may serve', function (): void {
    expect((new Pagination(1, 1, 10_000, '/blog'))->lastPage)->toBe(RenderContext::MAX_PAGE);
});

it('normalises the path it builds urls from', function (): void {
    expect((new Pagination(1, 10, 35, 'fr/blog/'))->nextUrl())->toBe('/fr/blog?page=2');
});
