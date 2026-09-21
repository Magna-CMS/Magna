<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Magna\Settings\PerformanceSettings;
use Magna\Settings\Setting;
use Magna\Settings\SettingsCache;

/**
 * A driver change has to be visible on the next boot, not an hour later.
 *
 * The cache driver is itself a setting, so PerformanceServiceProvider must
 * read the settings to learn which driver to switch to — the read lands on the
 * store configured in the file, and the save, made later in the same request,
 * busted the store the settings had just selected. The old store kept serving
 * the old snapshot until its hour expired: Performance Settings showed Redis
 * while System Insights went on reporting the database, and `cache:clear` was
 * no help because it only clears the default store.
 *
 * Hit in production on 2026-09-21 switching the marketplace hub to Redis.
 */
it('reads and writes the snapshot through the same store when the default changes underneath', function (): void {
    // Two real caching stores, because the bug needs the read to keep landing
    // on the store that holds the stale copy while writes go elsewhere. A
    // `null` store would hide it: nothing it is asked for is ever stale.
    config([
        'cache.stores.boot_store' => ['driver' => 'array'],
        'cache.stores.applied_store' => ['driver' => 'array'],
        'magna.settings.cache_store' => 'boot_store',
    ]);

    // Boot reads the settings to find out which driver to switch to, so the
    // snapshot is cached under whatever the config file said.
    config(['cache.default' => 'boot_store']);
    Setting::updateOrCreate(['group' => 'performance', 'key' => 'queue_connection'], ['value' => 'database']);
    expect(PerformanceSettings::get()->queue_connection)->toBe('database');

    // The provider now applies what it read, moving the default store out from
    // under everything that runs later in the request -- including the save.
    config(['cache.default' => 'applied_store']);

    $settings = PerformanceSettings::get();
    $settings->queue_connection = 'redis';
    $settings->save();

    // Next request boots on the config-file store again. Before the settings
    // snapshot was pinned, this is where it served the pre-change value for
    // the rest of the hour.
    config(['cache.default' => 'boot_store']);

    expect(PerformanceSettings::get()->queue_connection)->toBe('redis');
});

it('keeps the snapshot out of whichever store the settings happen to select', function (): void {
    config(['magna.settings.cache_store' => 'array', 'cache.default' => 'null']);

    PerformanceSettings::get();

    // The `null` store discards everything, so a snapshot found afterwards
    // proves the read did not go through cache.default.
    expect(Cache::store('array')->has('magna-settings:performance'))->toBeTrue();
});

it('falls back to the default store rather than dying on an unknown one', function (): void {
    // A typo in config must not take the settings layer down with it.
    config(['magna.settings.cache_store' => 'not-a-store']);

    expect(fn (): PerformanceSettings => PerformanceSettings::get())->not->toThrow(Exception::class);
});

it('busts the same store a plugin purge writes to', function (): void {
    config(['magna.settings.cache_store' => 'array', 'cache.default' => 'null']);

    Setting::updateOrCreate(['group' => 'acme_widget', 'key' => 'token'], ['value' => 'kept']);

    // Warm the snapshot, then purge the group the way an uninstall does.
    app(SettingsCache::class)->remember('acme_widget', fn (): array => ['token' => 'kept']);
    expect(Cache::store('array')->has('magna-settings:acme_widget'))->toBeTrue();

    app(SettingsCache::class)->forget('acme_widget');

    expect(Cache::store('array')->has('magna-settings:acme_widget'))->toBeFalse();
});
