<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Media\Http\Resources\MediaFolderResource;
use Magna\Media\MediaFolder;
use Symfony\Component\HttpFoundation\Response;

class FolderController extends ManagementController
{
    public function index(): JsonResponse
    {
        Gate::authorize('media.view');

        /** @var Collection<int, MediaFolder> $folders */
        $folders = MediaFolder::query()->orderBy('name')->get();

        return response()->json([
            'data' => MediaFolderResource::collection($folders),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('media.upload');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'string'],
        ]);

        $parentId = isset($validated['parent_id']) && is_string($validated['parent_id'])
            ? $validated['parent_id']
            : null;

        $parentPath = '';
        if ($parentId !== null) {
            $parent = MediaFolder::query()->find($parentId);
            if ($parent instanceof MediaFolder) {
                $parentPath = $parent->path;
            }
        }

        $slug = preg_replace('/[^a-z0-9\-_]/i', '-', $validated['name']) ?? $validated['name'];
        $path = trim($parentPath.'/'.$slug, '/');

        $folder = MediaFolder::create([
            'name' => $validated['name'],
            'parent_id' => $parentId,
            'path' => $path,
        ]);

        return response()->json(['data' => MediaFolderResource::make($folder)], 201);
    }

    public function destroy(Request $request, string $folder): Response
    {
        Gate::authorize('media.delete');

        $record = $this->findOrFail(MediaFolder::query(), $folder, 'Folder');

        $record->delete();

        return response()->noContent();
    }
}
