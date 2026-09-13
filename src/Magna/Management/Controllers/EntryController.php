<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Magna\Content\Entry;
use Magna\Content\EntryAuditRecorder;
use Magna\Content\EntryManager;
use Magna\Content\EntryStatus;
use Magna\Content\Http\Resources\EntryResource;
use Magna\Content\Models\Revision;
use Magna\Content\SchemaRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Error handling is by contract, not by catch block:
 * - an unknown type handle throws from resolveTypeOrFail() (404, rendered
 *   by the API exception renderer — S1-17: only reachable by callers whose
 *   permission check already passed, so handles cannot be enumerated);
 * - a SchemaException from the manager renders as a 400 via the renderable
 *   registered in bootstrap/app.php;
 * - only ValidationException is caught here, because this controller's 422
 *   shape is ['errors' => …] alone — a documented divergence from the
 *   framework default that clients already parse.
 */
class EntryController extends ManagementController
{
    public function __construct(
        private readonly EntryManager $manager,
        private readonly SchemaRegistry $schema,
        private readonly EntryAuditRecorder $audit,
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
        $this->resolveTypeOrFail($this->schema, $type);

        $paginator = Entry::type($type)
            ->orderByDesc('updated_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => EntryResource::collection($paginator->items()),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        Gate::authorize("content.{$type}.create");
        $this->resolveTypeOrFail($this->schema, $type);

        try {
            /** @var array<string, mixed> $data */
            $data = $request->all();
            $entry = $this->manager->create($type, $data, $this->actorId());
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $this->audit->created($entry, $this->actorId(), $request->ip());

        return response()->json(['data' => EntryResource::make($entry)], 201);
    }

    public function show(Request $request, string $type, string $id): JsonResponse
    {
        Gate::authorize("content.{$type}.view");
        $this->resolveTypeOrFail($this->schema, $type);

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        return response()->json(['data' => EntryResource::make($entry)]);
    }

    public function update(Request $request, string $type, string $id): JsonResponse
    {
        Gate::authorize("content.{$type}.update");
        $this->resolveTypeOrFail($this->schema, $type);

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        $before = $this->audit->snapshot($entry);

        try {
            /** @var array<string, mixed> $data */
            $data = $request->all();
            $entry = $this->manager->update($entry, $data, $this->actorId());
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $this->audit->updated($entry, $before, $this->actorId(), $request->ip());

        return response()->json(['data' => EntryResource::make($entry)]);
    }

    public function destroy(Request $request, string $type, string $id): Response
    {
        Gate::authorize("content.{$type}.delete");
        $this->resolveTypeOrFail($this->schema, $type);

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        $before = $this->audit->snapshot($entry);

        $this->manager->delete($entry, $this->actorId());

        $this->audit->deleted($before, $this->actorId(), $request->ip());

        return response()->noContent();
    }

    public function publish(Request $request, string $type, string $id): JsonResponse
    {
        Gate::authorize("content.{$type}.publish");
        $this->resolveTypeOrFail($this->schema, $type);

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        $atRaw = $request->input('publish_at');
        $at = is_string($atRaw) ? Carbon::parse($atRaw) : null;

        $entry = $this->manager->publish($entry, $at, $this->actorId());

        $this->audit->published($entry, $this->actorId(), $request->ip());

        return response()->json(['data' => EntryResource::make($entry)]);
    }

    public function unpublish(Request $request, string $type, string $id): JsonResponse
    {
        Gate::authorize("content.{$type}.publish");
        $this->resolveTypeOrFail($this->schema, $type);

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        if ($entry->status !== EntryStatus::Published) {
            return response()->json(['message' => 'Entry is not published.'], 422);
        }

        $entry = $this->manager->unpublish($entry, $this->actorId());

        $this->audit->unpublished($entry, $this->actorId(), $request->ip());

        return response()->json(['data' => EntryResource::make($entry)]);
    }

    public function draft(Request $request, string $type, string $id): JsonResponse
    {
        Gate::authorize("content.{$type}.update");
        $this->resolveTypeOrFail($this->schema, $type);

        $entry = $this->findOrFail(Entry::type($type), $id, 'Entry');

        if ($entry->status !== EntryStatus::Published) {
            return response()->json(['message' => 'Can only create a draft of a published entry.'], 422);
        }

        $draft = $this->manager->createDraftOf($entry);

        return response()->json(['data' => EntryResource::make($draft)], 201);
    }

    public function revisions(Request $request, string $type, string $id): JsonResponse
    {
        Gate::authorize("content.{$type}.view");
        $this->resolveTypeOrFail($this->schema, $type);

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
            'meta' => $this->paginationMeta($revisions),
        ]);
    }

    public function restore(Request $request, string $type, string $id, string $revision): JsonResponse
    {
        Gate::authorize("content.{$type}.update");
        $this->resolveTypeOrFail($this->schema, $type);

        $revisionQuery = Revision::query()
            ->where('entry_type', $type)
            ->where('entry_id', strtolower($id));

        $rev = $this->findOrFail($revisionQuery, $revision, 'Revision');

        $entry = $this->manager->restore($rev->id, $this->actorId());

        $this->audit->restored($entry, $rev->id, $this->actorId(), $request->ip());

        return response()->json(['data' => EntryResource::make($entry)]);
    }
}
