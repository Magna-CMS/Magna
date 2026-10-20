<?php

declare(strict_types=1);

namespace Magna\Frontend\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Public content changed: any cached public response tagged with one of
 * these tags is stale — or, with $everything, every cached response is.
 *
 * Dispatched by whichever plugin owns the content (a post published, a
 * comment approved, a category renamed); listened to by whichever plugin
 * caches public responses. Neither knows the other exists.
 */
final class PublicContentChanged
{
    use Dispatchable;

    /** @param  list<string>  $tags */
    public function __construct(
        public readonly array $tags = [],
        public readonly bool $everything = false,
        /** Who changed it — for logs only, never for decisions. */
        public readonly string $source = '',
    ) {}

    /** @param  list<string>  $tags */
    public static function tagged(array $tags, string $source = ''): self
    {
        return new self(array_values(array_unique($tags)), false, $source);
    }

    public static function all(string $source = ''): self
    {
        return new self([], true, $source);
    }
}
