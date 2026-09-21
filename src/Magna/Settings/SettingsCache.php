<?php

declare(strict_types=1);

namespace Magna\Settings;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * The one place that knows where a settings group's snapshot is cached.
 *
 * It is cached in an explicitly named store rather than through
 * `cache.default`, because the cache driver IS a setting. PerformanceServiceProvider
 * has to read the settings to learn which driver to switch to, so the read
 * lands on the store configured in the file while the save, made later in the
 * same request, busts the store the settings just selected. A driver change
 * then survived in the old store until the hour-long snapshot expired: the
 * admin form showed the new driver and the application went on using the old
 * one, with `cache:clear` no help because it only clears the default store.
 *
 * The key, the store and the lifetime live here together so that the read side
 * and every writer cannot drift apart again — PluginSettingsPurger busts the
 * same key from an entirely different subsystem.
 */
final class SettingsCache
{
    /**
     * How long a group's snapshot stands before it is re-read.
     *
     * Only a safety net: every writer busts the key, so this bounds the damage
     * from a write path nobody remembered rather than being the refresh
     * mechanism.
     */
    private const TTL_MINUTES = 60;

    /**
     * @param  Closure(): array<string, mixed>  $fetch
     * @return array<string, mixed>
     */
    public function remember(string $group, Closure $fetch): array
    {
        /** @var array<string, mixed> */
        return $this->store()->remember($this->key($group), now()->addMinutes(self::TTL_MINUTES), $fetch);
    }

    public function forget(string $group): void
    {
        $this->store()->forget($this->key($group));
    }

    private function key(string $group): string
    {
        return "magna-settings:{$group}";
    }

    /**
     * Falls back to the default store when the configured one is unknown: a
     * typo in config must not take the whole settings layer down with it.
     */
    private function store(): CacheRepository
    {
        $store = config('magna.settings.cache_store');

        if (! is_string($store) || ! is_array(config("cache.stores.{$store}"))) {
            return Cache::store();
        }

        return Cache::store($store);
    }
}
