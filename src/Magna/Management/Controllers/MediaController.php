<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Magna\Audit\AuditLog;
use Magna\Media\Events\MediaCreated;
use Magna\Media\Events\MediaDeleted;
use Magna\Media\Exceptions\MediaIngestException;
use Magna\Media\Exceptions\MimeTypeNotAllowedException;
use Magna\Media\Http\Resources\MediaResource;
use Magna\Media\Media;
use Magna\Media\MediaIngestor;
use Symfony\Component\HttpFoundation\Response;

class MediaController extends ManagementController
{
    public function __construct(
        private readonly MediaIngestor $ingestor,
    ) {}

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('media.upload');

        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json(['message' => 'No file uploaded.'], 422);
        }

        $tmpPath = $file->getRealPath();
        if ($tmpPath === false) {
            return response()->json(['message' => 'Could not read uploaded file.'], 422);
        }

        $altText = $request->string('alt')->value();
        $title = $request->string('title')->value();

        $folderId = $request->input('folder_id');
        $folderId = is_string($folderId) ? $folderId : null;

        try {
            $media = $this->ingestor->ingest(
                $tmpPath,
                $file->getClientOriginalName(),
                alt: $altText !== '' ? $altText : null,
                title: $title !== '' ? $title : null,
                folderId: $folderId,
            );
        } catch (MimeTypeNotAllowedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (MediaIngestException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        event(new MediaCreated($media, $this->actorId()));

        AuditLog::record(
            action: 'media.uploaded',
            actorId: $this->actorId(),
            ip: $request->ip(),
            subject: $media,
            after: ['id' => $media->id, 'filename' => $media->original_filename],
        );

        return response()->json(['data' => MediaResource::make($media)], 201);
    }

    public function show(Request $request, string $media): JsonResponse
    {
        Gate::authorize('media.view');

        $record = $this->findOrFail(Media::query(), $media, 'Media');

        return response()->json(['data' => MediaResource::make($record)]);
    }

    public function destroy(Request $request, string $media): Response
    {
        Gate::authorize('media.delete');

        $record = $this->findOrFail(Media::query(), $media, 'Media');

        $before = ['id' => $record->id, 'filename' => $record->original_filename];

        $record->delete();

        event(new MediaDeleted($record, $this->actorId()));

        AuditLog::record(
            action: 'media.deleted',
            actorId: $this->actorId(),
            ip: $request->ip(),
            subject: $record,
            before: $before,
        );

        return response()->noContent();
    }
}
