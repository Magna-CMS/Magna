<?php

declare(strict_types=1);

namespace Magna\Blocks\Resolution;

/**
 * Resolves the dynamic data a block needs at render time — the resolve step
 * of the render pipeline (docs/magna-pages/02-BLOCK-SYSTEM.md §4). A block's
 * view receives data; it never queries. Whatever a resolver returns is
 * attached to the view payload under `_resolved`.
 *
 * Implementations must be tolerant: a broken configuration (deleted content
 * type, bad field value) resolves to an empty/default payload — a block must
 * degrade, never take the page or preview down with it.
 */
interface ResolvesBlockData
{
    /**
     * The block handle this resolver serves (e.g. 'entries').
     */
    public function handle(): string;

    /**
     * Resolve dynamic data for one block instance.
     *
     * @param  array<string, mixed>  $data  The block's stored field data
     * @return array<string, mixed> The `_resolved` payload for the view
     */
    public function resolve(array $data): array;
}
