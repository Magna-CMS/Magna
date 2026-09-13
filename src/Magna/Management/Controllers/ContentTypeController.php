<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Audit\AuditLog;
use Magna\Content\ContentType;
use Magna\Content\Exceptions\SchemaException;
use Magna\Content\Field;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\Models\ContentTypeRecord;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;

class ContentTypeController extends ManagementController
{
    public function __construct(
        private readonly SchemaRegistry $schema,
        private readonly SchemaSyncer $syncer,
        private readonly FieldTypeRegistry $fieldTypes,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('settings.view');

        /** @var array<string, ContentType> $types */
        $types = $this->schema->all();

        return response()->json([
            'data' => array_values(array_map(fn (ContentType $t): array => $this->typeToArray($t), $types)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        /** @var array<string, mixed> $body */
        $body = $request->all();

        try {
            $type = ContentType::fromArray($body, $this->fieldTypes);
        } catch (SchemaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($this->schema->get($type->handle) !== null) {
            return response()->json(['message' => "Content type '{$type->handle}' already exists."], 409);
        }

        $this->schema->register($type);

        $record = ContentTypeRecord::create([
            'handle' => $type->handle,
            'display_name' => $type->displayName,
            'is_database_defined' => true,
            'schema' => $body,
        ]);

        // Stage 5 (S5-06): the DB record and in-memory registration above
        // are committed before the physical table exists. If syncAll()
        // (the actual DDL) fails, both previously stayed committed —
        // content_types/SchemaRegistry would claim a type with no backing
        // table, and the next request touching it would hit a raw "table
        // doesn't exist" SQL error instead of a clean validation message.
        try {
            $this->syncer->syncAll($this->schema, allowDestructive: false);
        } catch (\Throwable $e) {
            $record->delete();
            $this->schema->forget($type->handle);

            // The exception text is raw driver/DDL output — schema names,
            // SQL fragments, paths. That belongs in the log, not in an API
            // body any management-scope caller can read.
            report($e);

            return response()->json(['message' => 'Failed to create the content type table. The change was rolled back; see the server log for details.'], 500);
        }

        AuditLog::record(
            action: 'content_type.created',
            actorId: $this->actorId(),
            ip: $request->ip(),
            after: $this->typeToArray($type),
        );

        return response()->json(['data' => $this->typeToArray($type)], 201);
    }

    public function show(string $handle): JsonResponse
    {
        Gate::authorize('settings.view');

        $type = $this->resolveTypeOrFail($this->schema, $handle);

        return response()->json(['data' => $this->typeToArray($type)]);
    }

    public function update(Request $request, string $handle): JsonResponse
    {
        Gate::authorize('settings.manage');

        // The resolved value doubles as the rollback target below — the
        // schema that is actually reflected in the table right now.
        $previousType = $this->resolveTypeOrFail($this->schema, $handle);

        /** @var array<string, mixed> $body */
        $body = $request->all();
        $body['handle'] = $handle;

        try {
            $type = ContentType::fromArray($body, $this->fieldTypes);
        } catch (SchemaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $record = ContentTypeRecord::query()->where('handle', $handle)->first();
        $previousSchema = $record instanceof ContentTypeRecord ? $record->schema : null;

        if ($record instanceof ContentTypeRecord) {
            $record->schema = $body;
            $record->save();
        }

        $this->schema->register($type);

        // Stage 5 (S5-06): same rollback concern as store() — if the DDL
        // sync fails partway, don't leave the DB record / in-memory
        // registry pointing at the new (unapplied) schema instead of the
        // one that's actually reflected in the table.
        try {
            $allowDestructive = (bool) $request->input('allow_destructive', false);
            $this->syncer->syncAll($this->schema, allowDestructive: $allowDestructive);
        } catch (\Throwable $e) {
            if ($record instanceof ContentTypeRecord && $previousSchema !== null) {
                $record->schema = $previousSchema;
                $record->save();
            }
            $this->schema->register($previousType);

            // Same rule as store(): driver text goes to the log, not the body.
            report($e);

            return response()->json(['message' => 'Failed to apply the content type change. The previous schema was restored; see the server log for details.'], 500);
        }

        AuditLog::record(
            action: 'content_type.updated',
            actorId: $this->actorId(),
            ip: $request->ip(),
            after: $this->typeToArray($type),
        );

        return response()->json(['data' => $this->typeToArray($type)]);
    }

    /** @return array<string, mixed> */
    private function typeToArray(ContentType $type): array
    {
        return [
            'handle' => $type->handle,
            'display_name' => $type->displayName,
            'localizable' => $type->localizable,
            'draftable' => $type->draftable,
            'fields' => array_map(fn (Field $f): array => [
                'handle' => $f->handle,
                'type' => $f->type->typeName(),
                'required' => $f->required,
            ], $type->fields),
        ];
    }
}
