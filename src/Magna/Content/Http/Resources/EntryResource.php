<?php

declare(strict_types=1);

namespace Magna\Content\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Magna\Blocks\ReservedKeys;
use Magna\Content\EncryptedFieldRedactor;
use Magna\Content\Entry;
use Magna\Content\FieldTypes\BlocksField;
use Magna\Content\SchemaRegistry;

/**
 * Serializes a content Entry — base columns plus every schema-defined field
 * value for the entry's content type. The type is resolved from the entry's
 * own handle via the SchemaRegistry, so the resource is fully self-describing
 * given just an Entry (no caller needs to thread the ContentType through).
 *
 * @mixin Entry
 */
class EntryResource extends JsonResource
{
    /** @var Entry */
    public $resource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $handle = $this->resource->getHandle();
        $type = $handle !== null ? app(SchemaRegistry::class)->get($handle) : null;

        $data = [
            'id' => $this->id,
            'type' => $handle,
            'status' => $this->status->value,
            'locale' => $this->locale,
            'author_id' => $this->author_id,
            'draft_of' => $this->draft_of,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($type !== null) {
            foreach ($type->columnFields() as $field) {
                $value = $this->resource->getAttribute($field->handle);

                // Write-only secret semantics: a set encrypted value reads
                // back as the placeholder — in API responses AND in the
                // audit_logs before/after snapshots built from this resource,
                // which otherwise archived every secret in plaintext.
                // EntryManager strips the placeholder on write, so a client
                // round-tripping this payload can never overwrite the secret
                // with it. See EncryptedFieldRedactor.
                if ($field->encrypted && $value !== null) {
                    $value = EncryptedFieldRedactor::PLACEHOLDER;
                }

                // Blocks documents shed renderer-reserved '_' keys on the way
                // out — a document stored before the reserved-key rules must
                // not hand a planted `_resolved` to any client. See
                // Magna\Blocks\ReservedKeys.
                if ($field->type instanceof BlocksField && is_array($value)) {
                    $value = ReservedKeys::strip($value);
                }

                $data[$field->handle] = $value;
            }
        }

        return $data;
    }
}
