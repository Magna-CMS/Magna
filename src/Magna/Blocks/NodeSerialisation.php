<?php

declare(strict_types=1);

namespace Magna\Blocks;

/**
 * Shared serialisation helper: rebuild an original raw list, replacing each
 * entry that was parsed into a node with that node's serialised form and
 * passing every unparseable (non-array) entry through verbatim.
 *
 * @internal
 */
final class NodeSerialisation
{
    /**
     * @param  list<mixed>  $rawList
     * @param  array<int, BlockNode|ColumnNode|SectionNode>  $nodesByRawIndex
     * @return list<mixed>
     */
    public static function serialiseList(array $rawList, array $nodesByRawIndex): array
    {
        $out = [];
        foreach ($rawList as $index => $entry) {
            $out[] = isset($nodesByRawIndex[$index])
                ? $nodesByRawIndex[$index]->toArray()
                : $entry;
        }

        return $out;
    }
}
