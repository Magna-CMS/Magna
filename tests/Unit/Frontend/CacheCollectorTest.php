<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Magna\Frontend\CacheCollector;

it('collects each tag once and ignores blanks', function (): void {
    $collector = new CacheCollector;
    $collector->tag('blog:posts', ' ', 'blog:post:4');
    $collector->tag('blog:posts');

    expect($collector->tags())->toBe(['blog:posts', 'blog:post:4']);
});

it('keeps the earliest expiry it is told about', function (): void {
    $collector = new CacheCollector;
    $collector->expireAt(CarbonImmutable::parse('2026-10-22 09:00'));
    $collector->expireAt(CarbonImmutable::parse('2026-10-21 09:00'));
    $collector->expireAt(null);
    $collector->expireAt(new DateTime('2026-10-23 09:00'));

    expect($collector->expiresAt()?->toDateTimeString())->toBe('2026-10-21 09:00:00');
});

it('starts with no expiry and no veto', function (): void {
    $collector = new CacheCollector;

    expect($collector->expiresAt())->toBeNull()
        ->and($collector->vetoed())->toBeFalse()
        ->and($collector->tags())->toBe([]);
});

it('keeps the first reason a response must not be cached', function (): void {
    $collector = new CacheCollector;
    $collector->veto('shows the visitor their cart');
    $collector->veto('something else');

    expect($collector->vetoed())->toBeTrue()
        ->and($collector->vetoReason())->toBe('shows the visitor their cart');
});
