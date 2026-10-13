<?php

declare(strict_types=1);

namespace Magna\Blocks;

use Illuminate\Support\Str;

/**
 * One column node of the document tree (see PageTree for the tolerant-reader
 * contract shared by all node value objects).
 */
final class ColumnNode
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  list<BlockNode>  $blocks
     * @param  array<string, mixed>  $raw
     * @param  array<int, BlockNode>  $blocksByRawIndex
     */
    public function __construct(
        public readonly string $id,
        public readonly int $span,
        public readonly array $settings,
        public readonly array $blocks,
        private readonly array $raw = [],
        private readonly array $blocksByRawIndex = [],
    ) {}

    /**
     * @param  array<mixed, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $blocks = [];
        $blocksByRawIndex = [];
        if (isset($raw['blocks']) && is_array($raw['blocks'])) {
            foreach (array_values($raw['blocks']) as $index => $blockRaw) {
                if (is_array($blockRaw)) {
                    $node = BlockNode::fromArray($blockRaw);
                    $blocks[] = $node;
                    $blocksByRawIndex[$index] = $node;
                }
            }
        }

        /*
         * A span that is a number but not an int is ROUNDED, not discarded.
         *
         * This read `is_int($spanRaw) ? $spanRaw : 12`, so 1.05 — a ratio a
         * designer reaches for naturally, and what a JSON document carries the
         * moment anything divides — fell to the absent-value default of 12 and
         * clamped there. A narrow column silently became full width, which is
         * the worst shape for this failure: the page renders, nothing errors,
         * and the layout is wrong in a way that reads as a theme bug. Found
         * porting a design whose columns use 1.05/0.95 and 1.15/0.85.
         *
         * Rounding, because the clamp below already decides the bounds and
         * `min(12, ...)` on a float would hand a float to a twelfths grid.
         * Anything not numeric at all still means 12: a value this cannot read
         * is no information, and full width is the safe reading of none.
         */
        $spanRaw = $raw['span'] ?? 12;
        $span = is_int($spanRaw)
            ? $spanRaw
            : (is_float($spanRaw) || (is_string($spanRaw) && is_numeric($spanRaw)) ? (int) round((float) $spanRaw) : 12);

        /** @var array<string, mixed> $stringKeyed */
        $stringKeyed = array_filter($raw, static fn (mixed $k): bool => is_string($k), ARRAY_FILTER_USE_KEY);

        return new self(
            id: isset($raw['id']) && is_string($raw['id']) ? $raw['id'] : (string) Str::ulid(),
            span: max(1, min(12, $span)),
            settings: is_array($raw['settings'] ?? null) ? $raw['settings'] : [],
            blocks: $blocks,
            raw: $stringKeyed,
            blocksByRawIndex: $blocksByRawIndex,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = $this->raw;
        $out['id'] = $this->id;
        $out['span'] = $this->span;

        if (array_key_exists('settings', $this->raw)) {
            $out['settings'] = $this->settings;
        }

        if (isset($this->raw['blocks']) && is_array($this->raw['blocks'])) {
            $out['blocks'] = NodeSerialisation::serialiseList(array_values($this->raw['blocks']), $this->blocksByRawIndex);
        }

        return $out;
    }
}
