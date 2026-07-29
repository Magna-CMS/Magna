<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Controller;
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
}
