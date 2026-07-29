<?php

declare(strict_types=1);

namespace Magna\Delivery\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\FieldTypes\RelationField;
use Magna\Content\SchemaRegistry;
use Magna\Delivery\EntryTransformer;
use Magna\Delivery\ETagService;
use Magna\Delivery\PreviewTokenService;
use Magna\Delivery\RelationLoader;
use Magna\Delivery\ResponseCacheService;
use Magna\Delivery\SurrogateKeyCollector;
use Magna\Media\Media;
use Magna\Settings\GeneralSettings;
use Magna\Settings\LocalizationSettings;
use Symfony\Component\HttpFoundation\Response;

final class ContentSingleController extends DeliveryController
{
    public function __construct(
        private readonly SchemaRegistry $schema,
        private readonly EntryTransformer $transformer,
        private readonly RelationLoader $relationLoader,
        private readonly ETagService $etag,
        private readonly PreviewTokenService $previewTokens,
        private readonly ResponseCacheService $responseCache,
    ) {}

    public function __invoke(Request $request, string $type, string $id): Response
    {
        $outcome = $this->beginRequest($request, $type, $this->schema, $this->etag);
        if ($outcome instanceof Response) {
            return $outcome;
        }
        [$contentType, $keys, $cacheKey] = [$outcome->contentType, $outcome->keys, $outcome->cacheKey];

        $preview = $request->boolean('preview');
        $previewToken = $request->string('preview_token')->value();

        // Body cache is only for public (non-preview) requests.
        $bodyCacheKey = $this->responseCache->cacheKey($request);
        $isPublic = ! $preview || $previewToken === '';
        $wonLock = false;
        if ($isPublic) {
            $cachedBody = $this->responseCache->get($bodyCacheKey, $contentType->handle);
            if ($cachedBody !== null) {
                return $this->cachedResponse($cachedBody, $keys);
            }

            $wonLock = $this->responseCache->tryLock($bodyCacheKey);
            if (! $wonLock) {
                $stale = $this->responseCache->getStale($bodyCacheKey);
                if ($stale !== null) {
                    return $this->cachedResponse($stale, $keys);
                }
            }
        }

        // try/finally guarantees the rebuild lock is released on every exit
        // path below (404/403/400 early returns, serialization 500, success).
        try {
            return $this->resolveEntryResponse(
                $request, $contentType, $type, $id, $keys, $cacheKey, $bodyCacheKey, $preview, $previewToken, $isPublic,
            );
        } finally {
            if ($wonLock) {
                $this->responseCache->releaseLock($bodyCacheKey);
            }
        }
    }

    /**
     * Resolve, transform, cache and return a single entry. Split from
     * __invoke() so the caller can wrap it in try/finally for lock release.
     */
    private function resolveEntryResponse(
        Request $request,
        ContentType $contentType,
        string $type,
        string $id,
        SurrogateKeyCollector $keys,
        string $cacheKey,
        string $bodyCacheKey,
        bool $preview,
        string $previewToken,
        bool $isPublic,
    ): Response {
        // Build query — allow drafts if a preview token is present
        $query = Entry::type($type);
        if ($preview && $previewToken !== '') {
            $query->whereIn('status', [EntryStatus::Published->value, EntryStatus::Draft->value]);
        } else {
            $query->where('status', EntryStatus::Published->value);
        }

        // For localizable types, constrain by locale with fallback chain.
        if ($contentType->localizable) {
            $locSettings = LocalizationSettings::get();
            $generalSettings = GeneralSettings::get();
            $default = $generalSettings->default_locale;
            $requested = $request->string('locale')->value();
            $chain = array_values(array_unique([$requested, $locSettings->fallback_locale, $default, '']));

            $resolvedLocale = $default;
            foreach ($chain as $locale) {
                if ($query->clone()->where('locale', $locale)->exists()) {
                    $resolvedLocale = $locale;
                    break;
                }
            }
            $query->where('locale', $resolvedLocale);
            $keys->addLocale($resolvedLocale);
        }

        // Resolve by ULID or slug
        $isUlid = (bool) preg_match('/^[0-9A-Z]{26}$/i', $id);
        $hasSlug = $contentType->getField('slug') !== null;

        if ($isUlid) {
            $query->where('id', strtolower($id));
        } elseif ($hasSlug) {
            $query->where('slug', $id);
        } else {
            return response()->json(['message' => 'Entry not found.'], 404);
        }

        $entry = $query->first();
        if ($entry === null) {
            return response()->json(['message' => 'Entry not found.'], 404);
        }

        // Validate preview token — it is entry-scoped, so we check after finding the entry
        if ($preview && $previewToken !== '') {
            if (! $this->previewTokens->validate($previewToken, $entry->id, $type)) {
                return response()->json(['message' => 'Invalid or expired preview token.'], 403);
            }
        }

        // Parse ?with=
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

        // Batch-load media
        $entries = collect([$entry]);
        $mediaIds = $this->transformer->collectMediaIds($entries, $contentType);

        /** @var Collection<array-key, Media> $mediaCache */
        $mediaCache = $mediaIds !== []
            ? Media::whereIn('id', $mediaIds)->get()->keyBy('id')
            : collect();

        $relations = $relationHandles !== []
            ? $this->relationLoader->load($entries, $relationHandles, $type)
            : [];

        $data = $this->transformer->transformOne($entry, $contentType, $fields, $relations, $mediaCache, $keys);

        $body = ['data' => $data];
        $json = json_encode($body);
        if ($json === false) {
            return response()->json(['message' => 'Response serialization failed.'], 500);
        }

        $etagValue = '"'.hash('sha256', $json).'"';
        $this->etag->store($cacheKey, $etagValue, $type);

        if ($isPublic) {
            $this->responseCache->put($bodyCacheKey, $json, $contentType->handle);
        }

        return $this->deliveryResponse($json, $etagValue, $keys, 'MISS');
    }
}
