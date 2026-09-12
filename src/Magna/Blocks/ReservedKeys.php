<?php

declare(strict_types=1);

namespace Magna\Blocks;

/**
 * The renderer's reserved key namespace on block documents.
 *
 * Keys beginning with '_' (today: `_resolved`) belong to the transient view
 * payload that {@see Resolution\BlockDataResolver} builds — views render
 * them raw on the strength of having been produced by a sanitizing
 * resolver, so they must never travel inside a STORED document.
 * {@see PageTreeValidator} rejects them on save and
 * {@see BlockNode::fromArray()} sheds them at hydration; this helper is the
 * egress guard for documents that were stored before those rules existed
 * and are served without passing through either (the delivery API's default
 * resolve=0 passthrough, the management API's stored-document reads).
 */
final class ReservedKeys
{
    /**
     * Recursively remove every string key beginning with '_' from a stored
     * block document, at every level of the tree.
     *
     * @param  array<mixed, mixed>  $tree
     * @return array<mixed, mixed>
     */
    public static function strip(array $tree): array
    {
        $clean = [];

        foreach ($tree as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::strip($value) : $value;
        }

        return $clean;
    }
}
