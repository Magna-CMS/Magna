<?php

declare(strict_types=1);

namespace Magna\Content;

use Magna\Content\Models\ContentTypeRecord;
use Throwable;

/**
 * The three dependent writes behind creating or reshaping a content type —
 * registry registration, the content_types record, and the physical DDL —
 * with the compensating rollback that keeps them honest: if the DDL fails,
 * the record and the in-memory registration are put back the way they were,
 * so nothing anywhere claims a schema the table does not actually have.
 *
 * Extracted from ContentTypeController (per the collaborator pattern —
 * PluginContentTypeSyncer owns exactly this shape for plugin-declared
 * types): a hand-rolled compensating transaction is business logic, and the
 * thin-controller rule says a controller that try/catches around more than
 * one side-effecting call is doing a service's job.
 *
 * DDL cannot share a real transaction on MySQL (it commits whatever is
 * open), which is why this is compensation rather than DB::transaction —
 * the same constraint PluginManager::uninstall() documents.
 */
class ContentTypeSchemaWriter
{
    public function __construct(
        private readonly SchemaRegistry $schema,
        private readonly SchemaSyncer $syncer,
    ) {}

    /**
     * Register a brand-new type and build its table. On DDL failure the
     * record is deleted and the registration forgotten, then the failure is
     * rethrown for the caller to report.
     *
     * @param  array<string, mixed>  $body  the schema as submitted, stored verbatim
     */
    public function create(ContentType $type, array $body): void
    {
        $this->schema->register($type);

        $record = ContentTypeRecord::create([
            'handle' => $type->handle,
            'display_name' => $type->displayName,
            'is_database_defined' => true,
            'schema' => $body,
        ]);

        try {
            $this->syncer->syncAll($this->schema, allowDestructive: false);
        } catch (Throwable $e) {
            $record->delete();
            $this->schema->forget($type->handle);

            throw $e;
        }
    }

    /**
     * Apply a reshaped schema to an existing type. On DDL failure both the
     * stored record and the in-memory registration are restored to
     * $previousType — the schema that is actually reflected in the table.
     *
     * @param  array<string, mixed>  $body
     */
    public function update(ContentType $type, ContentType $previousType, array $body, bool $allowDestructive): void
    {
        $record = ContentTypeRecord::query()->where('handle', $type->handle)->first();
        $previousSchema = $record instanceof ContentTypeRecord ? $record->schema : null;

        if ($record instanceof ContentTypeRecord) {
            $record->schema = $body;
            $record->save();
        }

        $this->schema->register($type);

        try {
            $this->syncer->syncAll($this->schema, allowDestructive: $allowDestructive);
        } catch (Throwable $e) {
            if ($record instanceof ContentTypeRecord && $previousSchema !== null) {
                $record->schema = $previousSchema;
                $record->save();
            }
            $this->schema->register($previousType);

            throw $e;
        }
    }
}
