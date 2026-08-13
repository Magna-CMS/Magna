<?php

declare(strict_types=1);

namespace Magna\Blocks\DataSources;

/**
 * A named, listable feed of items a plugin exposes to page building — what
 * the Loop block iterates (docs/magna-pages/07-EXTENSIBILITY.md).
 *
 * Items are flat maps of scalars under CONVENTIONAL keys — `title`, `url`,
 * `description`, `date`, `image` — so one loop view can render any source
 * without knowing what a "job opening" or "upcoming event" is. A source
 * returns presentation-ready strings and never leaks model objects into
 * documents or views.
 *
 * fetch() runs on the PUBLIC render path: return only what an anonymous
 * visitor may see, regardless of any config a document carries — the
 * document is editor input, not an authorization.
 */
interface DataSource
{
    /** Unique handle, `vendor.name` style (`docs.latest`, `shop.products`). */
    public function handle(): string;

    /** Human label for pickers. */
    public function label(): string;

    /**
     * @param  array<string, mixed>  $config  The loop block's data (limit etc.)
     * @return list<array<string, string>>
     */
    public function fetch(array $config): array;
}
