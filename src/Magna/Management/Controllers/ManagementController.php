<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Controller;
use Magna\Content\ContentType;
use Magna\Content\SchemaRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base class for management API controllers.
 * Provides shared helpers used across all resource controllers.
 */
abstract class ManagementController extends Controller
{
    /** Return the authenticated actor's ULID as a string, or null if unavailable. */
    protected function actorId(): ?string
    {
        $id = auth()->id();
        if (is_string($id)) {
            return $id;
        }
        if (is_int($id)) {
            return (string) $id;
        }

        return null;
    }

    /**
     * Resolve a single record by (case-insensitive) id from an already-scoped
     * query, or throw a 404 that the API exception renderer turns into a
     * `{"message": "{$label} not found."}` JSON response.
     *
     * Centralizes the `find(strtolower($id))` + `{$label} not found.` pattern
     * that used to be duplicated across every Management controller. Throwing
     * (rather than returning a Model|JsonResponse union) keeps every caller a
     * single line — no `instanceof JsonResponse` guard — and lets the type
     * system know the result is always a Model.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    protected function findOrFail(Builder $query, string $id, string $label): Model
    {
        $record = (clone $query)
            ->where($query->getModel()->getKeyName(), strtolower($id))
            ->first();

        if ($record === null) {
            throw new NotFoundHttpException("{$label} not found.");
        }

        return $record;
    }

    /**
     * Resolve a content type handle from the registry, or throw the 404 the
     * API exception renderer turns into `{"message": "Content type '{$type}'
     * not found."}` — the same throwing shape as findOrFail(), for the same
     * reason. This used to be a four-line preamble repeated at the top of
     * every EntryController action (with the resolved value assigned to a
     * variable no action ever read again).
     */
    protected function resolveTypeOrFail(SchemaRegistry $schema, string $type): ContentType
    {
        $contentType = $schema->get($type);

        if ($contentType === null) {
            throw new NotFoundHttpException("Content type '{$type}' not found.");
        }

        return $contentType;
    }
}
