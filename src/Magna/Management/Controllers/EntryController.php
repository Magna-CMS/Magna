<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Magna\Audit\AuditLog;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\EntryStatus;
use Magna\Content\Exceptions\SchemaException;
use Magna\Content\Http\Resources\EntryResource;
use Magna\Content\Models\Revision;
use Magna\Content\SchemaRegistry;
use Magna\Settings\ApiSettings;
use Symfony\Component\HttpFoundation\Response;

class EntryController extends ManagementController
{
    public function __construct(
        private readonly EntryManager $manager,
        private readonly SchemaRegistry $schema,
    ) {}

    public function index(Request $request, string $type): JsonResponse
    {
        // S1-17: authorize before resolving the type, not after — a caller
        // with zero content permissions gets 403 for both real and
        // nonexistent type handles (Gate::before fails closed for an
        // unregistered ability), instead of a 404-vs-403 split that lets
        // them enumerate configured content-type handles without holding
        // any content permission at all.
        Gate::authorize("content.{$type}.view");

        $contentType = $this->resolveType($type);
        if ($contentType === null) {
            return $this->typeNotFound($type);
        }

        $apiSettings = ApiSettings::get();
        $perPage = min(max($request->integer('per_page', $apiSettings->default_per_page), 1), $apiSettings->max_per_page);
        $paginator = Entry::type($type)
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        return response()->json([
            'data' => EntryResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        // S1-17: see index() — authorize before resolving.
        Gate::authorize("content.{$type}.create");

        $contentType = $this->resolveType($type);
        if ($contentType === null) {
            return $this->typeNotFound($type);
        }

        try {
            /** @var array<string, mixed> $data */
            $data = $request->all();
            $entry = $this->manager->create($type, $data, $this->actorId());
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (SchemaException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        AuditLog::record(
            action: 'entry.created',
            actorId: $this->actorId(),
            ip: $request->ip(),
            subject: $entry,
            after: EntryResource::make($entry)->resolve(),
        );

        return response()->json(['data' => EntryResource::make($entry)], 201);
    }

    public function show(Request $request, string $type, string $id): JsonResponse
    {
        // S1-17: see index() — authorize before resolving.
        Gate::authorize("content.{$type}.view");

        $contentType = $this->resolveType($type);
        if ($contentType === null) {
            return $this->typeNotFound($type);
        }

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        return response()->json(['data' => EntryResource::make($entry)]);
    }

    public function update(Request $request, string $type, string $id): JsonResponse
    {
        // S1-17: see index() — authorize before resolving.
        Gate::authorize("content.{$type}.update");

        $contentType = $this->resolveType($type);
        if ($contentType === null) {
            return $this->typeNotFound($type);
        }

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        $before = EntryResource::make($entry)->resolve();

        try {
            /** @var array<string, mixed> $data */
            $data = $request->all();
            $entry = $this->manager->update($entry, $data, $this->actorId());
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (SchemaException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        AuditLog::record(
            action: 'entry.updated',
            actorId: $this->actorId(),
            ip: $request->ip(),
            subject: $entry,
            before: $before,
            after: EntryResource::make($entry)->resolve(),
        );

        return response()->json(['data' => EntryResource::make($entry)]);
    }

    public function destroy(Request $request, string $type, string $id): Response
    {
        // S1-17: see index() — authorize before resolving.
        Gate::authorize("content.{$type}.delete");

        $contentType = $this->resolveType($type);
        if ($contentType === null) {
            return $this->typeNotFound($type);
        }

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        $before = EntryResource::make($entry)->resolve();

        $this->manager->delete($entry, $this->actorId());

        AuditLog::record(
            action: 'entry.deleted',
            actorId: $this->actorId(),
            ip: $request->ip(),
            before: $before,
        );

        return response()->noContent();
    }

    public function publish(Request $request, string $type, string $id): JsonResponse
    {
        // S1-17: see index() — authorize before resolving.
        Gate::authorize("content.{$type}.publish");

        $contentType = $this->resolveType($type);
        if ($contentType === null) {
            return $this->typeNotFound($type);
        }

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        $atRaw = $request->input('publish_at');
        $at = is_string($atRaw) ? Carbon::parse($atRaw) : null;

        try {
            $entry = $this->manager->publish($entry, $at, $this->actorId());
        } catch (SchemaException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        AuditLog::record(
            action: 'entry.published',
            actorId: $this->actorId(),
            ip: $request->ip(),
            subject: $entry,
            after: ['status' => $entry->status->value, 'published_at' => $entry->published_at?->toIso8601String()],
        );

        return response()->json(['data' => EntryResource::make($entry)]);
    }

    public function unpublish(Request $request, string $type, string $id): JsonResponse
    {
        // S1-17: see index() — authorize before resolving.
        Gate::authorize("content.{$type}.publish");

        $contentType = $this->resolveType($type);
        if ($contentType === null) {
            return $this->typeNotFound($type);
        }

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        if ($entry->status !== EntryStatus::Published) {
            return response()->json(['message' => 'Entry is not published.'], 422);
        }

        try {
            $entry = $this->manager->unpublish($entry, $this->actorId());
        } catch (SchemaException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        AuditLog::record(
            action: 'entry.unpublished',
            actorId: $this->actorId(),
            ip: $request->ip(),
            subject: $entry,
        );

        return response()->json(['data' => EntryResource::make($entry)]);
    }

    public function draft(Request $request, string $type, string $id): JsonResponse
    {
        // S1-17: see index() — authorize before resolving.
        Gate::authorize("content.{$type}.update");

        $contentType = $this->resolveType($type);
        if ($contentType === null) {
            return $this->typeNotFound($type);
        }

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        if ($entry->status !== EntryStatus::Published) {
            return response()->json(['message' => 'Can only create a draft of a published entry.'], 422);
        }

        try {
            $draft = $this->manager->createDraftOf($entry);
        } catch (SchemaException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(['data' => EntryResource::make($draft)], 201);
    }

    public function revisions(Request $request, string $type, string $id): JsonResponse
    {
        // S1-17: see index() — authorize before resolving.
        Gate::authorize("content.{$type}.view");

        $contentType = $this->resolveType($type);
        if ($contentType === null) {
            return $this->typeNotFound($type);
        }

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        $revisions = Revision::query()
            ->where('entry_type', $type)
            ->where('entry_id', $entry->id)
            ->orderByDesc('created_at')
            ->paginate(25);

        /** @var array<int, array<string, mixed>> $items */
        $items = collect($revisions->items())->map(fn (Revision $r): array => [
            'id' => $r->id,
            'entry_id' => $r->entry_id,
            'entry_type' => $r->entry_type,
            'author_id' => $r->author_id,
            'payload' => $r->payload,
            'created_at' => $r->created_at->toIso8601String(),
        ])->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $revisions->currentPage(),
                'per_page' => $revisions->perPage(),
                'total' => $revisions->total(),
                'last_page' => $revisions->lastPage(),
            ],
        ]);
    }

    public function restore(Request $request, string $type, string $id, string $revision): JsonResponse
    {
        // S1-17: see index() — authorize before resolving.
        Gate::authorize("content.{$type}.update");

        $contentType = $this->resolveType($type);
        if ($contentType === null) {
            return $this->typeNotFound($type);
        }

        $revisionQuery = Revision::query()
            ->where('entry_type', $type)
            ->where('entry_id', strtolower($id));

        $rev = $this->findOrFail($revisionQuery, $revision, 'Revision');

        try {
            $entry = $this->manager->restore($rev->id, $this->actorId());
        } catch (SchemaException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        AuditLog::record(
            action: 'entry.restored',
            actorId: $this->actorId(),
            ip: $request->ip(),
            subject: $entry,
            after: ['restored_from_revision' => $rev->id],
        );

        return response()->json(['data' => EntryResource::make($entry)]);
    }

    private function resolveType(string $handle): ?ContentType
    {
        return $this->schema->get($handle);
    }

    private function typeNotFound(string $type): JsonResponse
    {
        return response()->json(['message' => "Content type '{$type}' not found."], 404);
    }
}
