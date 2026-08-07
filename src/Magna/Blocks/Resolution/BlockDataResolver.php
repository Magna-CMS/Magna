<?php

declare(strict_types=1);

namespace Magna\Blocks\Resolution;

use Magna\Blocks\BlockNode;
use Throwable;

/**
 * Dispatches block instances to their registered data resolver and shapes
 * the view payload (the block's array plus `_resolved`).
 *
 * This is the seam the Magna Pages renderer's resolve step builds on
 * (docs/magna-pages/02-BLOCK-SYSTEM.md §4); today its consumer is the
 * preview endpoint. Resolution never mutates the stored document —
 * `_resolved` exists only on the transient view payload.
 */
final class BlockDataResolver
{
    /** @var array<string, ResolvesBlockData> */
    private array $resolvers = [];

    public function register(ResolvesBlockData $resolver): void
    {
        $this->resolvers[$resolver->handle()] = $resolver;
    }

    /**
     * Build the view payload for a block: its serialised form, with
     * `_resolved` attached when a resolver is registered for its handle.
     *
     * @return array<string, mixed>
     */
    public function viewPayload(BlockNode $block): array
    {
        $payload = $block->toArray();

        $resolver = $this->resolvers[$block->block] ?? null;
        if ($resolver === null) {
            return $payload;
        }

        try {
            $payload['_resolved'] = $resolver->resolve($block->data);
        } catch (Throwable $e) {
            // A failed resolver degrades to an empty payload; the view's own
            // empty state renders. Never let one block break the page.
            report($e);
            $payload['_resolved'] = [];
        }

        return $payload;
    }
}
