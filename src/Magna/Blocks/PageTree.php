<?php

declare(strict_types=1);

namespace Magna\Blocks;

/**
 * The full block document (Section → Column → Block, with optional nested
 * block children). Node value objects: {@see SectionNode}, {@see ColumnNode},
 * {@see BlockNode}.
 *
 * Tolerant-reader design: every node keeps the raw array it was parsed from
 * and serialises by overlaying its normalised known fields onto that raw
 * array. Unknown keys — future format extensions such as `bindings`,
 * `conditions`, `localeOverlays`, per-breakpoint setting maps, or a ref
 * section's `part` — round-trip byte-for-byte instead of being silently
 * dropped. This is the load-bearing guarantee that lets older code save a
 * newer document without destroying constructs it does not understand
 * (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §A1).
 *
 * Two on-disk shapes are accepted and preserved on round-trip:
 *  - legacy: a plain list of section objects (implicit schema 1.0)
 *  - wrapped: {"schemaVersion": "1.0", "sections": [...], ...}
 *
 * The wrapper's unknown root keys (future: localeOverlays, …) are preserved.
 * These are immutable read-models for storing and rendering. Mutation happens
 * in the editors; the result is serialised to the entry's blocks_data column
 * as plain JSON.
 */
final class PageTree
{
    /** Schema versions this build can parse and serialise. */
    public const SUPPORTED_SCHEMA_VERSIONS = ['1.0'];

    /**
     * @param  list<SectionNode>  $sections
     * @param  array<string, mixed>  $rootExtra  Root keys of the wrapped form other than sections
     * @param  array<int, SectionNode>  $sectionsByRawIndex
     * @param  list<mixed>  $rawSections
     */
    public function __construct(
        public readonly array $sections,
        public readonly ?string $schemaVersion = null,
        private readonly bool $wrapped = false,
        private readonly array $rootExtra = [],
        private readonly array $sectionsByRawIndex = [],
        private readonly array $rawSections = [],
    ) {}

    /**
     * Parse raw blocks_data (already decoded as an array) into a PageTree.
     *
     * @param  array<mixed, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $wrapped = ! array_is_list($raw);

        /** @var list<mixed> $rawSections */
        $rawSections = [];
        $schemaVersion = null;
        $rootExtra = [];

        if ($wrapped) {
            $sectionsRaw = $raw['sections'] ?? [];
            $rawSections = is_array($sectionsRaw) ? array_values($sectionsRaw) : [];
            $schemaVersion = isset($raw['schemaVersion']) && is_string($raw['schemaVersion']) ? $raw['schemaVersion'] : null;
            foreach ($raw as $key => $value) {
                if (is_string($key) && $key !== 'sections') {
                    $rootExtra[$key] = $value;
                }
            }
        } else {
            $rawSections = $raw;
        }

        $sections = [];
        $sectionsByRawIndex = [];
        foreach ($rawSections as $index => $sectionRaw) {
            if (is_array($sectionRaw)) {
                $node = SectionNode::fromArray($sectionRaw);
                $sections[] = $node;
                $sectionsByRawIndex[$index] = $node;
            }
        }

        return new self(
            sections: $sections,
            schemaVersion: $schemaVersion,
            wrapped: $wrapped,
            rootExtra: $rootExtra,
            sectionsByRawIndex: $sectionsByRawIndex,
            rawSections: $rawSections,
        );
    }

    /**
     * Decode a JSON string into a PageTree.
     */
    public static function fromJson(string $json): self
    {
        $raw = json_decode($json, true);

        return self::fromArray(is_array($raw) ? $raw : []);
    }

    /**
     * Serialise back to the same shape the document was parsed from.
     *
     * @return array<mixed, mixed>
     */
    public function toArray(): array
    {
        $sections = NodeSerialisation::serialiseList($this->rawSections, $this->sectionsByRawIndex);

        if (! $this->wrapped) {
            return $sections;
        }

        // Non-sections root keys keep their original insertion order;
        // sections is appended (JSON object key order carries no meaning,
        // and array-level equality is what the round-trip suite asserts).
        $out = $this->rootExtra;
        $out['sections'] = $sections;

        return $out;
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
