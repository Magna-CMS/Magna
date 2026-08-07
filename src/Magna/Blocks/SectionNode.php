<?php

declare(strict_types=1);

namespace Magna\Blocks;

use Illuminate\Support\Str;

/**
 * One section node of the document tree — a plain section (columns of
 * blocks) or a `ref` pointing at a reusable template part. See PageTree for
 * the tolerant-reader contract shared by all node value objects.
 */
final class SectionNode
{
    public const TYPE_SECTION = 'section';

    public const TYPE_REF = 'ref';

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<ColumnNode>  $columns
     * @param  array<string, mixed>  $raw
     * @param  array<int, ColumnNode>  $columnsByRawIndex
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $settings,
        public readonly array $columns,
        private readonly array $raw = [],
        private readonly array $columnsByRawIndex = [],
    ) {}

    /**
     * @param  array<mixed, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $columns = [];
        $columnsByRawIndex = [];
        if (isset($raw['columns']) && is_array($raw['columns'])) {
            foreach (array_values($raw['columns']) as $index => $colRaw) {
                if (is_array($colRaw)) {
                    $node = ColumnNode::fromArray($colRaw);
                    $columns[] = $node;
                    $columnsByRawIndex[$index] = $node;
                }
            }
        }

        /** @var array<string, mixed> $stringKeyed */
        $stringKeyed = array_filter($raw, static fn (mixed $k): bool => is_string($k), ARRAY_FILTER_USE_KEY);

        return new self(
            id: isset($raw['id']) && is_string($raw['id']) ? $raw['id'] : (string) Str::ulid(),
            type: isset($raw['type']) && is_string($raw['type']) && $raw['type'] !== '' ? $raw['type'] : self::TYPE_SECTION,
            settings: is_array($raw['settings'] ?? null) ? $raw['settings'] : [],
            columns: $columns,
            raw: $stringKeyed,
            columnsByRawIndex: $columnsByRawIndex,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = $this->raw;
        $out['id'] = $this->id;
        $out['type'] = $this->type;

        // Never fabricate keys the source document did not carry (a ref
        // section has no settings/columns of its own).
        if (array_key_exists('settings', $this->raw)) {
            $out['settings'] = $this->settings;
        }

        if (isset($this->raw['columns']) && is_array($this->raw['columns'])) {
            $out['columns'] = NodeSerialisation::serialiseList(array_values($this->raw['columns']), $this->columnsByRawIndex);
        }

        return $out;
    }

    public function isRef(): bool
    {
        return $this->type === self::TYPE_REF;
    }

    /**
     * The referenced template-part id for a ref section (null otherwise).
     */
    public function part(): ?string
    {
        $part = $this->raw['part'] ?? null;

        return is_string($part) && $part !== '' ? $part : null;
    }

    /**
     * Return the tokenOverrides from section settings (empty array if none).
     *
     * @return array<string, string>
     */
    public function tokenOverrides(): array
    {
        $overrides = $this->settings['tokenOverrides'] ?? [];

        if (! is_array($overrides)) {
            return [];
        }

        $result = [];
        foreach ($overrides as $key => $value) {
            if (is_string($key) && $key !== ''
                && ! str_contains($key, ';') && ! str_contains($key, ':')
                && is_string($value) && $value !== ''
                && ! str_contains($value, ';')
                && ! str_contains($value, '(')  // blocks url(), calc(), and similar expressions
            ) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
