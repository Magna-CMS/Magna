<?php

declare(strict_types=1);

namespace Magna\Delivery;

use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

/**
 * Tag-aware cache wrapper for the delivery layer.
 *
 * Laravel's cache tags only work on a taggable store (array/redis/memcached).
 * Magna ships with the default CACHE_STORE=database, whose store is NOT
 * taggable — calling Cache::tags() on it throws
 * "This cache store does not support tagging." (Illuminate\Cache\Repository).
 *
 * This wrapper preserves tag-based invalidation on ANY store without changing
 * behaviour for tag-capable ones:
 *
 *   - Taggable store  → delegates straight to Cache::tags() (no perf change).
 *   - Non-taggable    → emulates tags with per-tag generation counters. Each
 *     tag has an integer "generation" in the cache; a stored item's physical
 *     key embeds the generations of every tag it carries. Flushing a tag
 *     increments its generation, so on the next read every item carrying that
 *     tag resolves to a fresh physical key (a miss) and the orphaned old
 *     entries fall out by their own TTL. That reproduces the "flush a tag ⇒
 *     invalidate every item carrying it" contract with plain get/put/increment
 *     — the only operations every Laravel store supports.
 *
 * The generation-key scheme never deletes orphaned bodies eagerly; they expire
 * by TTL (delivery bodies 300s, ETags 3600s), a bounded overshoot that is the
 * standard trade-off for tagless invalidation.
 */
final class TagAwareCache
{
    private ?bool $taggable = null;

    /**
     * Read a value stored under the given tag set. Returns null on miss (or a
     * non-string hit, which the delivery layer never writes).
     *
     * @param  list<string>  $tags
     */
    public function get(string $key, array $tags): ?string
    {
        $value = $this->supportsTags()
            ? Cache::tags($tags)->get($key)
            : Cache::get($this->namespacedKey($key, $tags));

        return is_string($value) ? $value : null;
    }

    /**
     * Store a value under the given tag set for $ttl seconds.
     *
     * @param  list<string>  $tags
     */
    public function put(string $key, string $value, int $ttl, array $tags): void
    {
        if ($this->supportsTags()) {
            Cache::tags($tags)->put($key, $value, $ttl);

            return;
        }

        Cache::put($this->namespacedKey($key, $tags), $value, $ttl);
    }

    /**
     * Invalidate every item carrying $tag.
     */
    public function flushTag(string $tag): void
    {
        if ($this->supportsTags()) {
            Cache::tags([$tag])->flush();

            return;
        }

        // Bump the tag's generation so every item embedding it maps to a new
        // physical key on the next read. Seed the counter first: a database/
        // file store's increment() is a no-op when the key is absent, which
        // would otherwise silently skip the very first invalidation.
        $generationKey = $this->generationKey($tag);
        Cache::add($generationKey, 0);
        Cache::increment($generationKey);
    }

    private function supportsTags(): bool
    {
        return $this->taggable ??= Cache::getStore() instanceof TaggableStore;
    }

    /**
     * Physical key for the non-taggable path: the logical key suffixed with a
     * hash of every tag's current generation. Any tag flush changes the hash.
     *
     * @param  list<string>  $tags
     */
    private function namespacedKey(string $key, array $tags): string
    {
        $parts = [];
        foreach ($tags as $tag) {
            $parts[] = $tag.'@'.$this->generation($tag);
        }

        return $key.':g'.hash('sha1', implode('|', $parts));
    }

    private function generation(string $tag): int
    {
        $value = Cache::get($this->generationKey($tag));

        return is_numeric($value) ? (int) $value : 0;
    }

    private function generationKey(string $tag): string
    {
        return 'magna.delivery.taggen.'.hash('sha1', $tag);
    }
}
