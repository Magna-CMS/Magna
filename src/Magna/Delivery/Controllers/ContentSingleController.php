<?php

declare(strict_types=1);

namespace Magna\Delivery\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Delivery\BlocksDocumentResolution;
use Magna\Delivery\EntryTransformer;
use Magna\Delivery\ETagService;
use Magna\Delivery\PreviewTokenService;
use Magna\Delivery\RelationLoader;
use Magna\Delivery\ResponseCacheService;
use Magna\Delivery\SingleEntryLookup;
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
        private readonly BlocksDocumentResolution $blocksResolution,
    ) {}

    public function __invoke(Request $request, string $type, string $id): Response
    {
        $context = $this->beginRequest($request, $type, $this->schema, $this->etag);

        $preview = $request->boolean('preview');
        $previewToken = $request->string('preview_token')->value();

        $lookup = new SingleEntryLookup(
            contentType: $context->contentType,
            keys: $context->keys,
            cacheKey: $context->cacheKey,
            type: $type,
            id: $id,
            bodyCacheKey: $this->responseCache->cacheKey($request),
            preview: $preview,
            previewToken: $previewToken,
            // Body cache is only for public (non-preview) requests.
            isPublic: ! $preview || $previewToken === '',
        );

        // Preview requests never touch the body cache — no lock to take.
        if (! $lookup->isPublic) {
            return $this->resolveEntryResponse($request, $lookup);
        }

        return $this->rebuildWithStampedeProtection(
            $this->responseCache,
            $lookup->bodyCacheKey,
            $lookup->contentType->handle,
            $lookup->keys,
            fn (): Response => $this->resolveEntryResponse($request, $lookup),
        );
    }

    /**
     * Resolve, transform, cache and return a single entry. Split from
     * __invoke() so the caller can wrap it in try/finally for lock release.
     */
    private function resolveEntryResponse(Request $request, SingleEntryLookup $lookup): Response
    {
        $entry = $this->findEntry($request, $lookup);

        $json = $this->encodeOrFail(['data' => $this->transformEntry($request, $lookup, $entry)]);

        $etagValue = '"'.hash('sha256', $json).'"';
        $this->etag->store($lookup->cacheKey, $etagValue, $lookup->type);

        if ($lookup->isPublic) {
            $this->responseCache->put($lookup->bodyCacheKey, $json, $lookup->contentType->handle);
        }

        return $this->deliveryResponse($json, $etagValue, $lookup->keys, 'MISS');
    }

    /**
     * Locate the entry the request addresses, by ULID or slug, within the
     * statuses and locale the lookup allows. Not-found and invalid-token
     * outcomes throw their response (the delivery-layer convention — see
     * DeliveryController), so the lock-releasing finally in __invoke()
     * covers them.
     */
    private function findEntry(Request $request, SingleEntryLookup $lookup): Entry
    {
        $query = Entry::type($lookup->type);
        if ($lookup->allowsDrafts()) {
            $query->whereIn('status', [EntryStatus::Published->value, EntryStatus::Draft->value]);
        } else {
            $query->where('status', EntryStatus::Published->value);
        }

        $this->constrainLocale($request, $lookup, $query);

        // Resolve by ULID or slug.
        $isUlid = (bool) preg_match('/^[0-9A-Z]{26}$/i', $lookup->id);
        $hasSlug = $lookup->contentType->getField('slug') !== null;

        if ($isUlid) {
            $query->where('id', strtolower($lookup->id));
        } elseif ($hasSlug) {
            $query->where('slug', $lookup->id);
        } else {
            throw new HttpResponseException(response()->json(['message' => 'Entry not found.'], 404));
        }

        $entry = $query->first();
        if ($entry === null) {
            throw new HttpResponseException(response()->json(['message' => 'Entry not found.'], 404));
        }

        // The preview token is entry-scoped, so it is checked after finding
        // the entry.
        if ($lookup->allowsDrafts() && ! $this->previewTokens->validate($lookup->previewToken, $entry->id, $lookup->type)) {
            throw new HttpResponseException(response()->json(['message' => 'Invalid or expired preview token.'], 403));
        }

        return $entry;
    }

    /**
     * For localizable types, constrain the query by locale with the
     * requested -> fallback -> default -> none chain, and record the locale
     * that won on the surrogate keys.
     *
     * @param  Builder<Entry>  $query
     */
    private function constrainLocale(Request $request, SingleEntryLookup $lookup, Builder $query): void
    {
        if (! $lookup->contentType->localizable) {
            return;
        }

        $default = GeneralSettings::get()->default_locale;
        $requested = $request->string('locale')->value();
        $chain = array_values(array_unique([$requested, LocalizationSettings::get()->fallback_locale, $default, '']));

        $resolvedLocale = $default;
        foreach ($chain as $locale) {
            if ($query->clone()->where('locale', $locale)->exists()) {
                $resolvedLocale = $locale;
                break;
            }
        }

        $query->where('locale', $resolvedLocale);
        $lookup->keys->addLocale($resolvedLocale);
    }

    /**
     * The transform phase: relation loading, media batch-load, field
     * selection, and optional block resolution.
     *
     * @return array<string, mixed>
     */
    private function transformEntry(Request $request, SingleEntryLookup $lookup, Entry $entry): array
    {
        $relationHandles = $this->parseRelationHandles($request, $lookup->contentType);
        $fields = $this->parseFieldSelection($request);

        $entries = collect([$entry]);
        $mediaCache = $this->loadMediaCache($entries, $lookup->contentType, $this->transformer);

        $relations = $relationHandles !== []
            ? $this->relationLoader->load($entries, $relationHandles, $lookup->type)
            : [];

        $data = $this->transformer->transformOne($entry, $lookup->contentType, $fields, $relations, $mediaCache, $lookup->keys);

        if ($request->boolean('resolve')) {
            $data = $this->blocksResolution->apply($data, $lookup->contentType);
        }

        return $data;
    }
}
