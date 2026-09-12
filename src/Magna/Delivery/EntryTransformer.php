<?php

declare(strict_types=1);

namespace Magna\Delivery;

use Illuminate\Support\Collection;
use Magna\Blocks\ReservedKeys;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\FieldTypes\BlocksField;
use Magna\Content\FieldTypes\MediaField;
use Magna\Content\FieldTypes\RelationField;
use Magna\Contracts\DecoratesDeliveryResponse;
use Magna\Media\Media;
use Magna\Media\MediaUrlResolver;

/**
 * Converts Entry models to delivery API array payloads.
 *
 * Media fields are resolved against a pre-loaded cache to avoid N+1 queries.
 * All resolved media IDs are registered in the SurrogateKeyCollector for
 * CDN tag-based invalidation.
 */
final class EntryTransformer
{
    public function __construct(
        private readonly MediaUrlResolver $urlResolver,
    ) {}

    /**
     * @param  Collection<int, Entry>  $entries
     * @param  list<string>|null  $fields  Field handles to include (null = all column fields)
     * @param  array<string, array<string, array<int, Entry>>>  $relations  Pre-loaded by RelationLoader
     * @param  Collection<array-key, Media>  $mediaCache  Keyed by id
     * @return array<int, array<string, mixed>>
     */
    public function transformMany(
        Collection $entries,
        ContentType $type,
        ?array $fields,
        array $relations,
        Collection $mediaCache,
        SurrogateKeyCollector $keys,
    ): array {
        /** @var array<int, array<string, mixed>> $result */
        $result = [];
        foreach ($entries as $entry) {
            $result[] = $this->transformOne($entry, $type, $fields, $relations, $mediaCache, $keys);
        }

        return $result;
    }

    /**
     * @param  list<string>|null  $fields
     * @param  array<string, array<string, array<int, Entry>>>  $relations
     * @param  Collection<array-key, Media>  $mediaCache
     * @return array<string, mixed>
     */
    public function transformOne(
        Entry $entry,
        ContentType $type,
        ?array $fields,
        array $relations,
        Collection $mediaCache,
        SurrogateKeyCollector $keys,
    ): array {
        $keys->addEntry($entry->id);

        /** @var array<string, mixed> $result */
        $result = [
            'id' => $entry->id,
            'type' => $type->handle,
            'status' => $entry->status->value,
            'locale' => $entry->locale,
            'published_at' => $entry->published_at?->toIso8601String(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];

        // Column fields
        foreach ($type->columnFields() as $field) {
            if ($fields !== null && ! in_array($field->handle, $fields, true)) {
                continue;
            }

            $value = $entry->getAttribute($field->handle);

            if ($field->type instanceof MediaField) {
                if ($field->type->isJsonColumn()) {
                    $ids = is_array($value) ? $value : [];
                    $resolved = [];
                    foreach ($ids as $id) {
                        if (is_string($id)) {
                            $item = $this->resolveMedia($id, $mediaCache, $keys);
                            if ($item !== null) {
                                $resolved[] = $item;
                            }
                        }
                    }
                    $result[$field->handle] = $resolved;
                } else {
                    $result[$field->handle] = is_string($value)
                        ? $this->resolveMedia($value, $mediaCache, $keys)
                        : null;
                }
            } elseif ($field->type instanceof BlocksField) {
                // Egress guard for documents stored before the reserved-key
                // rules: '_'-prefixed keys are renderer-owned (`_resolved`)
                // and must never leave as if a sanitizing resolver wrote
                // them. ?resolve=1 re-attaches the real thing afterwards.
                $result[$field->handle] = is_array($value) ? ReservedKeys::strip($value) : $value;
            } else {
                $result[$field->handle] = $value;
            }
        }

        // Relation fields (only those populated via ?with=)
        foreach ($type->fields as $field) {
            if (! $field->type instanceof RelationField) {
                continue;
            }
            if ($fields !== null && ! in_array($field->handle, $fields, true)) {
                continue;
            }
            if (! array_key_exists($field->handle, $relations)) {
                continue;
            }

            $relatedEntries = $relations[$field->handle][$entry->id] ?? [];
            $result[$field->handle] = array_map(
                fn (Entry $rel): array => [
                    'id' => $rel->id,
                    'type' => $rel->getHandle(),
                    'status' => $rel->status->value,
                ],
                $relatedEntries,
            );
        }

        // Allow enabled plugins to inject extra keys (e.g. SEO meta) into the payload.
        if (app()->bound('magna.delivery_decorators')) {
            /** @var list<DecoratesDeliveryResponse> $decorators */
            $decorators = app()->make('magna.delivery_decorators');
            foreach ($decorators as $decorator) {
                $decorator->decorateDeliveryEntry($type->handle, $entry->id, $result);
            }
        }

        return $result;
    }

    /**
     * Collect all media IDs referenced by a set of entries.
     * Used to pre-load media in a single query before transforming.
     *
     * @param  Collection<int, Entry>  $entries
     * @return list<string>
     */
    public function collectMediaIds(Collection $entries, ContentType $type): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            foreach ($type->columnFields() as $field) {
                if (! $field->type instanceof MediaField) {
                    continue;
                }
                $value = $entry->getAttribute($field->handle);
                if ($field->type->isJsonColumn()) {
                    if (is_array($value)) {
                        foreach ($value as $id) {
                            if (is_string($id)) {
                                $ids[] = $id;
                            }
                        }
                    }
                } elseif (is_string($value)) {
                    $ids[] = $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  Collection<array-key, Media>  $mediaCache
     * @return array<string, mixed>|null
     */
    private function resolveMedia(string $id, Collection $mediaCache, SurrogateKeyCollector $keys): ?array
    {
        $media = $mediaCache->get($id);
        if (! $media instanceof Media) {
            return null;
        }

        $keys->addMedia($media->id);

        return [
            'id' => $media->id,
            'url' => $this->urlResolver->publicUrl($media),
            'alt' => $media->alt,
            'title' => $media->title,
            'width' => $media->width,
            'height' => $media->height,
            'mime_type' => $media->mime_type,
            'srcset' => $this->urlResolver->srcset($media),
        ];
    }
}
