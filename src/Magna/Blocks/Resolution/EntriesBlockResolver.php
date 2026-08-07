<?php

declare(strict_types=1);

namespace Magna\Blocks\Resolution;

use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;

/**
 * Resolves the `entries` block: queries the latest published entries of the
 * configured content type and shapes them for the block view
 * (title / slug / excerpt / published_at).
 *
 * Tolerant by design: an unknown or removed content type resolves to an
 * empty list (the view renders its empty state), and sort keys targeting
 * fields the type does not define fall back to publication date.
 */
final class EntriesBlockResolver implements ResolvesBlockData
{
    /** Hard cap regardless of stored configuration. */
    public const MAX_LIMIT = 50;

    public const DEFAULT_LIMIT = 6;

    public function __construct(private readonly SchemaRegistry $schemaRegistry) {}

    public function handle(): string
    {
        return 'entries';
    }

    public function resolve(array $data): array
    {
        $typeHandle = $data['content_type'] ?? null;
        if (! is_string($typeHandle) || $typeHandle === '') {
            return ['entries' => []];
        }

        $type = $this->schemaRegistry->get($typeHandle);
        if ($type === null) {
            return ['entries' => []];
        }

        $limitRaw = $data['limit'] ?? self::DEFAULT_LIMIT;
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        [$sortColumn, $sortDirection] = $this->sortFor($data['sort'] ?? null, $type);

        $entries = Entry::type($typeHandle)
            ->where('status', EntryStatus::Published->value)
            ->orderBy($sortColumn, $sortDirection)
            ->limit($limit)
            ->get();

        $hasTitle = $this->typeHasField($type, 'title');
        $hasSlug = $this->typeHasField($type, 'slug');
        $hasExcerpt = $this->typeHasField($type, 'excerpt');

        $shaped = [];
        foreach ($entries as $entry) {
            $shaped[] = [
                'id' => $this->stringOrNull($entry->getKey()) ?? '',
                'title' => $hasTitle ? $this->stringOrNull($entry->getAttribute('title')) : null,
                'slug' => $hasSlug ? $this->stringOrNull($entry->getAttribute('slug')) : null,
                'excerpt' => $hasExcerpt ? $this->stringOrNull($entry->getAttribute('excerpt')) : null,
                'published_at' => $this->dateString($entry->getAttribute('published_at')),
            ];
        }

        return ['entries' => $shaped];
    }

    /**
     * Map the stored sort key onto a real column, falling back to
     * publication date when the target field does not exist on the type.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    private function sortFor(mixed $sort, ContentType $type): array
    {
        $key = is_string($sort) ? $sort : 'published_at_desc';

        return match (true) {
            $key === 'published_at_asc' => ['published_at', 'asc'],
            $key === 'title_asc' && $this->typeHasField($type, 'title') => ['title', 'asc'],
            $key === 'title_desc' && $this->typeHasField($type, 'title') => ['title', 'desc'],
            default => ['published_at', 'desc'],
        };
    }

    private function typeHasField(ContentType $type, string $handle): bool
    {
        foreach ($type->fields as $field) {
            if ($field->handle === $handle && ! $field->type->isRelationOnly()) {
                return true;
            }
        }

        return false;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $this->stringOrNull($value);
    }
}
