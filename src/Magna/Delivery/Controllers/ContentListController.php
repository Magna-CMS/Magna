<?php

declare(strict_types=1);

namespace Magna\Delivery\Controllers;

use Illuminate\Http\Request;
use Magna\Content\ContentType;
use Magna\Content\Field;
use Magna\Content\SchemaRegistry;
use Magna\Delivery\BlocksDocumentResolution;
use Magna\Delivery\CursorPaginator;
use Magna\Delivery\DeliveryQueryBuilder;
use Magna\Delivery\EntryTransformer;
use Magna\Delivery\ETagService;
use Magna\Delivery\Exceptions\DeliveryException;
use Magna\Delivery\RelationLoader;
use Magna\Delivery\ResponseCacheService;
use Magna\Delivery\SurrogateKeyCollector;
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
        private readonly BlocksDocumentResolution $blocksResolution,
    ) {}

    public function __invoke(Request $request, string $type): Response
    {
        $context = $this->beginRequest($request, $type, $this->schema, $this->etag);
        [$contentType, $keys, $cacheKey] = [$context->contentType, $context->keys, $context->cacheKey];

        $bodyCacheKey = $this->responseCache->cacheKey($request);

        return $this->rebuildWithStampedeProtection(
            $this->responseCache,
            $bodyCacheKey,
            $contentType->handle,
            $keys,
            fn (): Response => $this->buildFreshResponse($request, $contentType, $type, $keys, $cacheKey, $bodyCacheKey),
        );
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
        $relationHandles = $this->parseRelationHandles($request, $contentType);
        $fields = $this->parseFieldSelection($request);

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

        $mediaCache = $this->loadMediaCache($entries, $contentType, $this->transformer);

        // Batch-load relations (Q: 1 pivot + 1 per distinct relation type)
        $relations = $relationHandles !== []
            ? $this->relationLoader->load($entries, $relationHandles, $type)
            : [];

        $data = $this->transformer->transformMany($entries, $contentType, $fields, $relations, $mediaCache, $keys);

        if ($request->boolean('resolve')) {
            $data = $this->blocksResolution->applyMany($data, $contentType);
        }

        $body = [
            'data' => $data,
            'meta' => [
                'next_cursor' => $paginated->nextCursor,
                'has_more' => $paginated->hasMore,
                'per_page' => $paginated->perPage,
            ],
            'included' => (object) [],
        ];

        $json = $this->encodeOrFail($body);

        $etagValue = '"'.hash('sha256', $json).'"';
        $this->etag->store($cacheKey, $etagValue, $type);
        $this->responseCache->put($bodyCacheKey, $json, $contentType->handle);

        return $this->deliveryResponse($json, $etagValue, $keys, 'MISS');
    }
}
