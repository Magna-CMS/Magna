<?php

declare(strict_types=1);

use Magna\Frontend\Events\PublicContentChanged;
use Magna\Frontend\Events\PublicUrlsMoved;
use Magna\Frontend\UrlMove;

it('normalises both ends of a move to site-relative paths', function (): void {
    $move = new UrlMove('blog/old-slug/', '/blog/new-slug');

    expect($move->from)->toBe('/blog/old-slug')
        ->and($move->to)->toBe('/blog/new-slug')
        ->and($move->status)->toBe(301);
});

it('refuses a status no redirect answers with', function (): void {
    new UrlMove('/a', '/b', 200);
})->throws(InvalidArgumentException::class);

it('drops moves onto themselves, which would loop', function (): void {
    $event = new PublicUrlsMoved([
        new UrlMove('/blog/a', '/blog/a/'),
        new UrlMove('/blog/a', '/blog/b'),
    ], 'test');

    expect($event->moves)->toHaveCount(1)
        ->and($event->moves[0]->to)->toBe('/blog/b');
});

it('names either the tags that changed or everything', function (): void {
    $tagged = PublicContentChanged::tagged(['blog:posts', 'blog:posts', 'blog:post:3'], 'blog');
    $all = PublicContentChanged::all('settings');

    expect($tagged->tags)->toBe(['blog:posts', 'blog:post:3'])
        ->and($tagged->everything)->toBeFalse()
        ->and($all->everything)->toBeTrue()
        ->and($all->tags)->toBe([]);
});
