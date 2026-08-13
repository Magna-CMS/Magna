<?php

declare(strict_types=1);

namespace Magna\Blocks\DynamicTags;

/**
 * Every dynamic tag enabled plugins expose, keyed by handle. Same shape as
 * BlockRegistry: plugins contribute at enable-time via the
 * RegistersDynamicTags contract; consumers (bindings, pickers) only ever
 * read.
 */
final class DynamicTagRegistry
{
    /** @var array<string, DynamicTag> */
    private array $tags = [];

    public function register(DynamicTag $tag): void
    {
        $this->tags[$tag->handle()] = $tag;
    }

    public function get(string $handle): ?DynamicTag
    {
        return $this->tags[$handle] ?? null;
    }

    /** @return array<string, DynamicTag> */
    public function all(): array
    {
        return $this->tags;
    }
}
