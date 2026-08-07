<?php

declare(strict_types=1);

namespace Magna\Blocks\Resolution;

use Magna\Blocks\BlockNode;

/**
 * Resolves an entire block document: every block in every section/column
 * (including nested children) is passed through the BlockDataResolver so its
 * transient `_resolved` payload is attached in place.
 *
 * Operates on the raw document array and only touches the structural keys it
 * knows (`sections`, `columns`, `blocks`, `children`) — unknown keys pass
 * through untouched, same tolerant-reader stance as PageTree. Ref sections
 * carry no columns and are returned as-is; expanding them is the pages
 * plugin's concern (TemplatePartResolver), not core's.
 *
 * The result is a VIEW payload — `_resolved` must never be written back to
 * storage (BlockDataResolver's contract).
 */
final class DocumentResolver
{
    public function __construct(private readonly BlockDataResolver $resolver) {}

    /**
     * @param  array<mixed, mixed>  $document  Raw blocks_data (legacy list or wrapped form)
     * @return array<mixed, mixed>
     */
    public function resolve(array $document): array
    {
        if (array_is_list($document)) {
            return $this->resolveSections($document);
        }

        if (isset($document['sections']) && is_array($document['sections'])) {
            $document['sections'] = $this->resolveSections(array_values($document['sections']));
        }

        return $document;
    }

    /**
     * @param  list<mixed>  $sections
     * @return list<mixed>
     */
    private function resolveSections(array $sections): array
    {
        return array_map(function (mixed $section): mixed {
            if (! is_array($section) || ! isset($section['columns']) || ! is_array($section['columns'])) {
                return $section;
            }

            $section['columns'] = array_map(function (mixed $column): mixed {
                if (! is_array($column) || ! isset($column['blocks']) || ! is_array($column['blocks'])) {
                    return $column;
                }

                $column['blocks'] = array_map(
                    fn (mixed $block): mixed => is_array($block) ? $this->resolveBlock($block) : $block,
                    $column['blocks'],
                );

                return $column;
            }, $section['columns']);

            return $section;
        }, $sections);
    }

    /**
     * @param  array<mixed, mixed>  $blockRaw
     * @return array<string, mixed>
     */
    private function resolveBlock(array $blockRaw): array
    {
        $payload = $this->resolver->viewPayload(BlockNode::fromArray($blockRaw));

        if (isset($payload['children']) && is_array($payload['children'])) {
            $payload['children'] = array_map(
                fn (mixed $child): mixed => is_array($child) ? $this->resolveBlock($child) : $child,
                $payload['children'],
            );
        }

        return $payload;
    }
}
