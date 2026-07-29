<?php

declare(strict_types=1);

namespace Magna\Delivery\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Magna\Content\ContentType;
use Magna\Content\Field;
use Magna\Content\FieldTypes\RelationField;
use Magna\Content\SchemaRegistry;
use Magna\Delivery\CursorPaginator;
use Magna\Delivery\DeliveryQueryBuilder;
use Magna\Delivery\EntryTransformer;
use Magna\Delivery\ETagService;
use Magna\Delivery\Exceptions\DeliveryException;
use Magna\Delivery\RelationLoader;
use Magna\Delivery\ResponseCacheService;
use Magna\Delivery\SurrogateKeyCollector;
use Magna\Media\Media;
use Magna\Settings\ApiSettings;
use Symfony\Component\HttpFoundation\Response;

final class ContentListController extends DeliveryController
{
    public function __construct(
        private readonly SchemaRegistry $schema,
        private readonly DeliveryQueryBuilder $queryBuilder,
        private readonly CursorPaginator $paginator,
        private readonly EntryTransformer $transformer,
        private readonly RelationLoader $relationLoader,
        private readonly ETagService $etag,
        private readonly ResponseCacheService $responseCache,
    ) {}

    public function __invoke(Request $request, string $type): Response
    {
        $outcome = $this->beginRequest($request, $type, $this->schema, $this->etag);
        if ($outcome instanceof Response) {
            return $outcome;
        }
        [$contentType, $keys, $cacheKey] = [$outcome->contentType, $outcome->keys, $outcome->cacheKey];

        $bodyCacheKey = $this->responseCache->cacheKey($request);
        $cachedBody = $this->responseCache->get($bodyCacheKey, $contentType->handle);
        if ($cachedBody !== null) {
            return $this->cachedResponse($cachedBody, $keys);
        }

        // Stampede protection: if we can't win the rebuild lock, serve stale.
        $wonLock = $this->responseCache->tryLock($bodyCacheKey);
        if (! $wonLock) {
            $stale = $this->responseCache->getStale($bodyCacheKey);
            if ($stale !== null) {
                return $this->cachedResponse($stale, $keys);
            }
            // No stale copy — fall through to a fresh DB query.
        }

        // try/finally guarantees the rebuild lock is released on every exit
        // path below (early-return 400s, DeliveryException, serialization 500,
        // and the success path) — not only after a successful put().
        try {
            return $this->buildFreshResponse($request, $contentType, $type, $keys, $cacheKey, $bodyCacheKey);
        } finally {
            if ($wonLock) {
                $this->responseCache->releaseLock($bodyCacheKey);
            }
        }
    }

    /**
     * Build a fresh (uncached) list response: parse query params, run the
     * query, transform, cache the body, and return it.
     */
    private function buildFreshResponse(
        Request $request,
        ContentType $contentType,
        string $type,
        SurrogateKeyCollector $keys,
        string $cacheKey,
        string $bodyCacheKey,
    ): Response {
        // Parse ?with= relation handles
        $withParam = $request->string('with')->value();
        /** @var list<string> $relationHandles */
        $relationHandles = [];
        if ($withParam !== '') {
            foreach (array_map('trim', explode(',', $withParam)) as $handle) {
                $field = $contentType->getField($handle);
                if ($field === null || ! $field->type instanceof RelationField) {
                    return response()->json(['message' => "Unknown relation field: '{$handle}'."], 400);
                }
                $relationHandles[] = $handle;
            }
        }

        // Parse ?fields=
        $fieldsParam = $request->string('fields')->value();
        $fields = $fieldsParam !== '' ? array_map('trim', explode(',', $fieldsParam)) : null;

        // Parse ?sort=
        $sortParam = $request->string('sort')->value();
        $sortAsc = ! str_starts_with($sortParam, '-');
        $sortColumn = ltrim($sortParam, '-');

        if ($sortColumn === '') {
            $sortColumn = 'id';
            $sortAsc = false;
        }

        // Validate sort column against schema
        $validSortColumns = array_merge(
            ['id', 'published_at', 'created_at', 'updated_at'],
            array_map(fn (Field $f): string => $f->handle, $contentType->columnFields()),
        );
        if (! in_array($sortColumn, $validSortColumns, true)) {
            return response()->json(['message' => "Invalid sort column: '{$sortColumn}'."], 400);
        }

        // Parse ?per_page= and ?cursor=
        $apiSettings = ApiSettings::get();
        $perPageRaw = $request->input('per_page', $apiSettings->default_per_page);
        $perPage = min(max(is_numeric($perPageRaw) ? (int) $perPageRaw : $apiSettings->default_per_page, 1), $apiSettings->max_per_page);
        $cursor = $request->string('cursor')->value();
        $cursor = $cursor !== '' ? $cursor : null;

        // Build and execute query
        $resolvedLocale = null;
        try {
            $query = $this->queryBuilder->build($contentType, $request, $resolvedLocale);
            $paginated = $this->paginator->paginate($query, $perPage, $cursor, $sortColumn, $sortAsc);
        } catch (DeliveryException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        if ($resolvedLocale !== null) {
            $keys->addLocale($resolvedLocale);
        }

        $entries = $paginated->entries;

        // Batch-load media (Q: 1 query if any media fields exist)
        $mediaIds = $this->transformer->collectMediaIds($entries, $contentType);

        /** @var Collection<array-key, Media> $mediaCache */
        $mediaCache = $mediaIds !== []
            ? Media::whereIn('id', $mediaIds)->get()->keyBy('id')
            : collect();

        // Batch-load relations (Q: 1 pivot + 1 per distinct relation type)
        $relations = $relationHandles !== []
            ? $this->relationLoader->load($entries, $relationHandles, $type)
            : [];

        $data = $this->transformer->transformMany($entries, $contentType, $fields, $relations, $mediaCache, $keys);

        $body = [
            'data' => $data,
            'meta' => [
                'next_cursor' => $paginated->nextCursor,
                'has_more' => $paginated->hasMore,
                'per_page' => $paginated->perPage,
            ],
            'included' => (object) [],
        ];

        $json = json_encode($body);
        if ($json === false) {
            return response()->json(['message' => 'Response serialization failed.'], 500);
        }

        $etagValue = '"'.hash('sha256', $json).'"';
        $this->etag->store($cacheKey, $etagValue, $type);
        $this->responseCache->put($bodyCacheKey, $json, $contentType->handle);

        return $this->deliveryResponse($json, $etagValue, $keys, 'MISS');
    }
}
