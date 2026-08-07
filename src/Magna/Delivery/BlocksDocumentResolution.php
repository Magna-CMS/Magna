<?php

declare(strict_types=1);

namespace Magna\Delivery;

use Magna\Blocks\Resolution\DocumentResolver;
use Magna\Content\ContentType;
use Magna\Content\FieldTypes\BlocksField;

/**
 * Implements ?resolve=1 on the delivery API: every blocks field on a
 * transformed entry payload is run through the block resolve seam so headless
 * consumers receive `_resolved` data (published entry lists, sanitized
 * richtext, menu trees) alongside the raw document — the same payload the
 * server-side renderer works from, without reimplementing resolvers
 * client-side.
 *
 * Freshness note: resolved data comes from OTHER entries, but the response
 * body cache is tagged only with the requested type. A change to a resolved
 * entry therefore shows up after the body cache TTL (≤5 min) rather than
 * instantly — same trade-off as the rendered pages_cache, bounded and
 * documented rather than hidden.
 */
final class BlocksDocumentResolution
{
    public function __construct(private readonly DocumentResolver $documents) {}

    /**
     * @param  array<string, mixed>  $payload  One transformed entry payload
     * @return array<string, mixed>
     */
    public function apply(array $payload, ContentType $type): array
    {
        foreach ($type->columnFields() as $field) {
            if (! $field->type instanceof BlocksField) {
                continue;
            }

            $value = $payload[$field->handle] ?? null;
            if (is_array($value)) {
                $payload[$field->handle] = $this->documents->resolve($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, array<string, mixed>>
     */
    public function applyMany(array $payloads, ContentType $type): array
    {
        return array_map(fn (array $payload): array => $this->apply($payload, $type), $payloads);
    }
}
