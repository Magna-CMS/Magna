<?php

declare(strict_types=1);

namespace Magna\Content;

use Magna\Content\FieldTypes\SlugField;

/**
 * Auto-populates slug fields from their configured `from` source field
 * (EntryManager collaborator — extracted to keep the orchestrator under the
 * god-class ceiling).
 *
 * For hierarchical types the uniqueness scope is the sibling set (same
 * parent), not the whole type — "intro" under /docs and under /guides are
 * different URLs and neither should be suffixed.
 */
class AutoSlugApplier
{
    public function __construct(private readonly SlugGenerator $slugs) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function apply(ContentType $type, array $data, ?Entry $entry = null): array
    {
        // Parent context for sibling-scoped dedup: an explicit parent_id in
        // the payload wins; an update without one keeps the entry's current.
        $parentId = null;
        if (array_key_exists('parent_id', $data)) {
            $parentId = is_string($data['parent_id']) && $data['parent_id'] !== '' ? $data['parent_id'] : null;
        } elseif ($entry !== null) {
            $parentId = $entry->parent_id;
        }

        foreach ($type->fields as $field) {
            if (! ($field->type instanceof SlugField)) {
                continue;
            }

            $rawSlug = $data[$field->handle] ?? null;
            $currentSlug = is_string($rawSlug) ? $rawSlug : '';
            if ($currentSlug !== '') {
                continue;
            }

            // An update that doesn't touch the slug keeps it — regenerating
            // from a changed source field would silently rename the URL.
            // Explicitly sending an empty slug re-triggers generation.
            if ($entry !== null && ! array_key_exists($field->handle, $data)) {
                $existing = $entry->getAttribute($field->handle);
                if (is_string($existing) && $existing !== '') {
                    continue;
                }
            }

            $fromHandle = $field->rawData['from'] ?? null;
            if (! is_string($fromHandle) || $fromHandle === '') {
                continue;
            }

            $sourceValue = $data[$fromHandle] ?? null;
            if (! is_string($sourceValue) || $sourceValue === '') {
                continue;
            }

            $rawLocale = $data['locale'] ?? null;
            $locale = is_string($rawLocale) ? $rawLocale : '';

            $data[$field->handle] = $this->slugs->generate(
                $type,
                $field->handle,
                $sourceValue,
                $locale !== '' ? $locale : null,
                scopeToParent: $type->hierarchical,
                parentId: $parentId,
            );
        }

        return $data;
    }
}
