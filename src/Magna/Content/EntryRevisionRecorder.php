<?php

declare(strict_types=1);

namespace Magna\Content;

use Magna\Content\Models\Revision;

/**
 * Writes revision snapshots for EntryManager (collaborator extracted per the
 * god-class ceiling — EntryManager orchestrates, this records).
 *
 * Revisions are append-only full-payload snapshots stamped with a kind
 * (why the snapshot exists) and the block-document schemaVersion the payload
 * was written under (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §A4).
 */
class EntryRevisionRecorder
{
    public function __construct(private readonly SchemaRegistry $registry) {}

    public function record(
        Entry $entry,
        string $typeHandle,
        ?string $actorId,
        string $kind = Revision::KIND_SAVE,
        ?string $label = null,
    ): void {
        $type = $this->registry->get($typeHandle);
        if ($type === null) {
            return;
        }

        $payload = [];
        foreach ($type->columnFields() as $field) {
            $payload[$field->handle] = $entry->getAttribute($field->handle);
        }

        Revision::create([
            'entry_type' => $typeHandle,
            'entry_id' => $entry->getKey(),
            'payload' => $payload,
            'kind' => $kind,
            'label' => $label,
            'schema_version' => $this->payloadSchemaVersion($type, $payload),
            'author_id' => $actorId,
        ]);
    }

    /**
     * The block-document schemaVersion carried by the payload's first blocks
     * field, when the wrapped document form is in use (null for the legacy
     * list form, which is implicit 1.0).
     *
     * @param  array<string, mixed>  $payload
     */
    private function payloadSchemaVersion(ContentType $type, array $payload): ?string
    {
        foreach ($type->fields as $field) {
            if ($field->type->typeName() !== 'blocks') {
                continue;
            }

            $document = $payload[$field->handle] ?? null;
            if (is_array($document)
                && ! array_is_list($document)
                && is_string($document['schemaVersion'] ?? null)
            ) {
                return $document['schemaVersion'];
            }
        }

        return null;
    }
}
