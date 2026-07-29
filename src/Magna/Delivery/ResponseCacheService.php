<?php

declare(strict_types=1);

namespace Magna\Delivery;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Full-response body cache for the delivery API with stampede protection.
 *
 * Stores the serialised JSON string keyed by the canonical request signature
 * and tagged with the global delivery tag plus the content type's tag.
 * Flushing 'magna.delivery.type.{handle}' invalidates every cached response
 * for that type; flushing 'magna.delivery' invalidates all of them. Tagging is
 * done through TagAwareCache so it works on the default (non-taggable) database
 * store as well as taggable ones.
 *
 * The tag set is fixed (global + type) and identical at read and write —
 * previously the write path passed the full per-response surrogate-key set
 * (entry/media/locale) while the read path passed only the type, so on a
 * taggable store the two namespaces never matched and the body cache always
 * missed. Type-level invalidation matches ETagService and the invalidation
 * events (DeliveryCacheInvalidator only ever flushes per-type or globally).
 *
 * Stampede protection: on a cache miss the caller can acquire a rebuild lock
 * (tryLock). Concurrent requests that don't win the lock are served STALE
 * from the grace store (a secondary untagged key with a longer TTL) while
 * the winner rebuilds. This prevents all workers from hitting the DB
 * simultaneously when a hot key expires.
 */
final class ResponseCacheService
{
    /** Seconds to hold a cached response body. */
    private const TTL = 300;

    /** Seconds to hold the stale-grace copy for stampede serving. */
    private const GRACE_TTL = 600;

    /** Lock hold time — the rebuild window before the lock auto-releases. */
    private const LOCK_TTL = 10;

    public function __construct(private readonly TagAwareCache $cache) {}

    /**
     * Build a stable cache key from the request path + sorted query params.
     * Identical to ETagService::cacheKey() so the two caches are co-located.
     */
    public function cacheKey(Request $request): string
    {
        /** @var array<string, mixed> $params */
        $params = $request->query();
        ksort($params);

        // Scope included for the same reason ETagService includes it — a cache
        // key that ignores who is asking is a content leak waiting for the
        // first scoped token.
        return 'magna.delivery.body.'.hash(
            'sha256',
            $request->path().'?'.http_build_query($params).'|'.ETagService::scopeSignature($request),
        );
    }

    /**
     * Retrieve a cached response body for the given content type.
     * Returns null on miss; the caller should call tryLock() and, if it wins,
     * rebuild + put(). If it loses the lock, call getStale() for a grace copy.
     */
    public function get(string $cacheKey, string $typeHandle): ?string
    {
        return $this->cache->get($cacheKey, $this->tagsFor($typeHandle));
    }

    /**
     * Retrieve the stale grace copy (survives past normal TTL for stampede serving).
     */
    public function getStale(string $cacheKey): ?string
    {
        $value = Cache::get($cacheKey.':grace');

        return is_string($value) ? $value : null;
    }

    /**
     * Try to acquire the rebuild lock for this cache key.
     * Returns true if this worker won and must rebuild + put().
     * Returns false if another worker holds it — caller should serve stale.
     */
    public function tryLock(string $cacheKey): bool
    {
        return (bool) Cache::lock($cacheKey.':lock', self::LOCK_TTL)->get();
    }

    /**
     * Release the rebuild lock (called after put() to unblock waiters early).
     */
    public function releaseLock(string $cacheKey): void
    {
        Cache::lock($cacheKey.':lock', self::LOCK_TTL)->forceRelease();
    }

    /**
     * Store a response body tagged with the global + type tags.
     * Also writes a separate untagged grace copy for stampede serving.
     */
    public function put(string $cacheKey, string $body, string $typeHandle): void
    {
        $this->cache->put($cacheKey, $body, self::TTL, $this->tagsFor($typeHandle));
        Cache::put($cacheKey.':grace', $body, self::GRACE_TTL);
    }

    /**
     * Flush all cached responses that carry the given type's tag.
     */
    public function invalidateType(string $typeHandle): void
    {
        $this->cache->flushTag('magna.delivery.type.'.$typeHandle);
    }

    /**
     * Flush the entire delivery body cache (used when media changes touch all types).
     */
    public function invalidateAll(): void
    {
        $this->cache->flushTag('magna.delivery');
    }

    /**
     * The fixed tag set for a type's cached responses: the global delivery tag
     * plus the type tag. Identical at read and write.
     *
     * @return list<string>
     */
    private function tagsFor(string $typeHandle): array
    {
        return ['magna.delivery', 'magna.delivery.type.'.$typeHandle];
    }
}
