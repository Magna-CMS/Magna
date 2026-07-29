<?php

declare(strict_types=1);

namespace Magna\Delivery;

use Illuminate\Http\Request;
use Magna\Users\User;

/**
 * Manages ETag generation, caching, and conditional-request 304 detection.
 *
 * ETags are SHA-256 hashes of the response JSON, stored via TagAwareCache so
 * they work on the default (non-taggable) database cache store as well as
 * taggable stores. The cache tag 'magna.delivery.type.{handle}' is flushed on
 * content changes, automatically invalidating all ETags for that type.
 */
final class ETagService
{
    public function __construct(private readonly TagAwareCache $cache) {}

    /**
     * Build a stable cache key for a given request (path + sorted query params
     * + the caller's authorisation scope).
     */
    public function cacheKey(Request $request): string
    {
        /** @var array<string, mixed> $params */
        $params = $request->query();
        ksort($params);

        return 'magna.delivery.etag.'.hash(
            'sha256',
            $request->path().'?'.http_build_query($params).'|'.self::scopeSignature($request),
        );
    }

    /**
     * What the caller is allowed to see, folded into the cache key.
     *
     * Today every delivery token carries the same flat `delivery` ability, so
     * this collapses to a single bucket and costs nothing. It is here because
     * of what happens the day it doesn't: the moment a token is scoped to a
     * locale, a subset of types, or a tenant, a key built from path+query
     * alone starts serving one caller's response to another — a cross-tenant
     * content leak introduced by a change in a completely different file, with
     * nothing in Delivery to notice it. Cheap now, unfixable-in-hindsight
     * later.
     */
    public static function scopeSignature(Request $request): string
    {
        $user = $request->user();

        // Narrowed to the model rather than probed with method_exists(): an
        // installed plugin can add its own guard model, so the resolver's type
        // is a union in one install and a single class in another — and only
        // this one carries a Sanctum token.
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if ($token === null) {
            return 'anonymous';
        }

        /** @var list<string> $abilities */
        $abilities = is_array($token->abilities ?? null) ? $token->abilities : [];
        sort($abilities);

        return hash('sha256', implode(',', $abilities));
    }

    /**
     * Check whether the request's If-None-Match matches the cached ETag.
     * Returns the cached ETag string if the response is unmodified (caller
     * should return 304), or null if the content must be re-fetched.
     *
     * The typeHandle must match the one used in store() so the tag set is identical.
     */
    public function check(Request $request, string $cacheKey, string $typeHandle): ?string
    {
        $ifNoneMatch = $request->header('If-None-Match');
        if (! is_string($ifNoneMatch) || $ifNoneMatch === '') {
            return null;
        }

        $cached = $this->cache->get($cacheKey, ['magna.delivery', 'magna.delivery.type.'.$typeHandle]);
        if ($cached === null) {
            return null;
        }

        return $ifNoneMatch === $cached ? $cached : null;
    }

    /**
     * Persist an ETag in the tagged cache for future conditional checks.
     */
    public function store(string $cacheKey, string $etag, string $typeHandle): void
    {
        $this->cache->put($cacheKey, $etag, 3600, ['magna.delivery', 'magna.delivery.type.'.$typeHandle]);
    }

    /**
     * Flush all cached ETags for a content type (call after publish/update/delete).
     */
    public function invalidateType(string $typeHandle): void
    {
        $this->cache->flushTag('magna.delivery.type.'.$typeHandle);
    }

    /**
     * Flush the entire delivery ETag cache (call when media is created/deleted,
     * since media can appear in any entry response regardless of type).
     */
    public function invalidateAllMedia(): void
    {
        $this->cache->flushTag('magna.delivery');
    }

    /**
     * Compute a quoted ETag from JSON-serialisable data.
     */
    public function compute(mixed $data): string
    {
        $json = json_encode($data);

        return '"'.hash('sha256', is_string($json) ? $json : '').'"';
    }
}
