<?php

declare(strict_types=1);

namespace Magna\Blocks;

use Illuminate\Support\Str;

/**
 * One block node of the document tree (see PageTree for the tolerant-reader
 * contract shared by all node value objects).
 */
final class BlockNode
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $data
     * @param  list<BlockNode>  $children  Parsed child nodes (container blocks)
     * @param  array<string, mixed>  $raw  Original array, preserved for serialisation
     * @param  array<int, BlockNode>  $childrenByRawIndex
     */
    public function __construct(
        public readonly string $id,
        public readonly string $block,
        public readonly array $settings,
        public readonly array $data,
        public readonly array $children = [],
        private readonly array $raw = [],
        private readonly array $childrenByRawIndex = [],
    ) {}

    /**
     * @param  array<mixed, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $children = [];
        $childrenByRawIndex = [];
        if (isset($raw['children']) && is_array($raw['children'])) {
            foreach (array_values($raw['children']) as $index => $childRaw) {
                if (is_array($childRaw)) {
                    $node = self::fromArray($childRaw);
                    $children[] = $node;
                    $childrenByRawIndex[$index] = $node;
                }
            }
        }

        /** @var array<string, mixed> $stringKeyed */
        $stringKeyed = array_filter($raw, static fn (mixed $k): bool => is_string($k), ARRAY_FILTER_USE_KEY);

        return new self(
            id: isset($raw['id']) && is_string($raw['id']) ? $raw['id'] : (string) Str::ulid(),
            block: isset($raw['block']) && is_string($raw['block']) ? $raw['block'] : '',
            settings: is_array($raw['settings'] ?? null) ? $raw['settings'] : [],
            data: is_array($raw['data'] ?? null) ? $raw['data'] : [],
            children: $children,
            raw: $stringKeyed,
            childrenByRawIndex: $childrenByRawIndex,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = $this->raw;
        $out['id'] = $this->id;
        $out['block'] = $this->block;

        if (array_key_exists('settings', $this->raw)) {
            $out['settings'] = $this->settings;
        }
        if (array_key_exists('data', $this->raw)) {
            $out['data'] = $this->data;
        }

        if (isset($this->raw['children']) && is_array($this->raw['children'])) {
            $out['children'] = NodeSerialisation::serialiseList(array_values($this->raw['children']), $this->childrenByRawIndex);
        }

        return $out;
    }
}
