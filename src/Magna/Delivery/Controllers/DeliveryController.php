<?php

declare(strict_types=1);

namespace Magna\Delivery\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\FieldTypes\RelationField;
use Magna\Content\SchemaRegistry;
use Magna\Delivery\DeliveryRequestContext;
use Magna\Delivery\EntryTransformer;
use Magna\Delivery\ETagService;
use Magna\Delivery\SurrogateKeyCollector;
use Magna\Media\Media;
use Magna\Settings\ApiSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared behavior for the public Delivery API's __invoke() controllers
 * (ContentListController, ContentSingleController): the API-enabled/resolve
 * content-type/304-ETag preamble every one of them starts with, the query
 * parsing they share, and the cached-body response shape every one of them
 * ends with.
 *
 * Short-circuits (503/404/304, bad query input) THROW HttpResponseException —
 * the framework's own carrier for "this response, now" — rather than
 * returning a Response|DeliveryRequestContext union that every caller must
 * unpick with an instanceof guard. That union is the exact shape the
 * community review flagged on the management side, where findOrFail() has
 * thrown ever since; an architecture rule now holds every controller
 * namespace to it.
 */
abstract class DeliveryController extends Controller
{
    /**
     * Runs the shared preamble: API-enabled check, content-type resolution,
     * surrogate-key collector setup, and a 304 short-circuit against the
     * request's ETag.
     */
    protected function beginRequest(
        Request $request,
        string $type,
        SchemaRegistry $schema,
        ETagService $etag,
    ): DeliveryRequestContext {
        if (! ApiSettings::get()->api_enabled) {
            throw new HttpResponseException(
                response()->json(['message' => 'The API is currently disabled.'], 503),
            );
        }

        $contentType = $schema->get($type);
        if ($contentType === null) {
            throw new HttpResponseException(
                response()->json(['message' => "Content type '{$type}' not found."], 404),
            );
        }

        $keys = new SurrogateKeyCollector;
        $keys->addType($type);

        $cacheKey = $etag->cacheKey($request);
        $matchedEtag = $etag->check($request, $cacheKey, $type);
        if ($matchedEtag !== null) {
            throw new HttpResponseException(
                response('', 304)->header('ETag', $matchedEtag),
            );
        }

        return new DeliveryRequestContext($contentType, $keys, $cacheKey);
    }

    /**
     * Parse ?with= into validated relation handles. An unknown handle is the
     * caller's mistake and answers 400 immediately.
     *
     * @return list<string>
     */
    protected function parseRelationHandles(Request $request, ContentType $contentType): array
    {
        $withParam = $request->string('with')->value();
        if ($withParam === '') {
            return [];
        }

        $handles = [];
        foreach (array_map('trim', explode(',', $withParam)) as $handle) {
            $field = $contentType->getField($handle);
            if ($field === null || ! $field->type instanceof RelationField) {
                throw new HttpResponseException(
                    response()->json(['message' => "Unknown relation field: '{$handle}'."], 400),
                );
            }
            $handles[] = $handle;
        }

        return $handles;
    }

    /**
     * Parse ?fields= into a selection list, or null for "everything".
     *
     * @return list<string>|null
     */
    protected function parseFieldSelection(Request $request): ?array
    {
        $fieldsParam = $request->string('fields')->value();

        return $fieldsParam !== '' ? array_map('trim', explode(',', $fieldsParam)) : null;
    }

    /**
     * Batch-load the media rows the given entries reference (one query when
     * any media fields exist, none otherwise).
     *
     * @param  Collection<int, Entry>  $entries
     * @return Collection<array-key, Media>
     */
    protected function loadMediaCache(Collection $entries, ContentType $contentType, EntryTransformer $transformer): Collection
    {
        $mediaIds = $transformer->collectMediaIds($entries, $contentType);

        /** @var Collection<array-key, Media> $mediaCache */
        $mediaCache = $mediaIds !== []
            ? Media::whereIn('id', $mediaIds)->get()->keyBy('id')
            : collect();

        return $mediaCache;
    }

    /**
     * Encode the response body, answering a 500 rather than emitting broken
     * JSON when a value refuses to serialise.
     *
     * @param  array<string, mixed>  $body
     */
    protected function encodeOrFail(array $body): string
    {
        $json = json_encode($body);

        if ($json === false) {
            throw new HttpResponseException(
                response()->json(['message' => 'Response serialization failed.'], 500),
            );
        }

        return $json;
    }

    /** The final JSON response for a fresh (non-cached) body. */
    protected function deliveryResponse(string $json, string $etagValue, SurrogateKeyCollector $keys, string $cacheStatus): Response
    {
        return response($json, 200, [
            'Content-Type' => 'application/json',
            'ETag' => $etagValue,
            'Cache-Control' => 'public, s-maxage=60, stale-while-revalidate=300',
            'Surrogate-Key' => $keys->headerValue(),
            'X-Cache' => $cacheStatus,
        ]);
    }

    protected function cachedResponse(string $body, SurrogateKeyCollector $keys): Response
    {
        return $this->deliveryResponse($body, '"'.hash('sha256', $body).'"', $keys, 'HIT');
    }
}
