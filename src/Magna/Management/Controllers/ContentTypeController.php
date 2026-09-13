<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Audit\AuditLog;
use Magna\Content\ContentType;
use Magna\Content\ContentTypeSchemaWriter;
use Magna\Content\Exceptions\SchemaException;
use Magna\Content\Field;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;

class ContentTypeController extends ManagementController
{
    public function __construct(
        private readonly SchemaRegistry $schema,
        private readonly ContentTypeSchemaWriter $writer,
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

        // Registry + record + DDL and the compensating rollback all live in
        // the writer. The exception text is raw driver/DDL output — schema
        // names, SQL fragments, paths — so it goes to the log, never into an
        // API body any management-scope caller can read.
        try {
            $this->writer->create($type, $body);
        } catch (\Throwable $e) {
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

        // Same seam as store(): the writer owns the record/registry/DDL
        // dance and its rollback to $previousType on failure.
        try {
            $this->writer->update($type, $previousType, $body, (bool) $request->input('allow_destructive', false));
        } catch (\Throwable $e) {
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
