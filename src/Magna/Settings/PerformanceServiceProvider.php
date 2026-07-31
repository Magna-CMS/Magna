<?php

declare(strict_types=1);

namespace Magna\Settings;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Magna\Install\Installer;

/**
 * Applies the DB-backed PerformanceSettings (cache/queue driver, Redis
 * connection, Octane server) to the runtime config, so changing them on the
 * admin Settings page takes effect without touching .env.
 *
 * Runs in boot() rather than register(): Cache/Queue/Redis managers resolve
 * their driver lazily from config the first time a facade call actually needs
 * one, which always happens after every provider's register() AND boot() has
 * run — so overriding config here is still in time, and DB/cache facades used
 * to read the setting itself are already fully available.
 *
 * Reading PerformanceSettings::get() below uses whatever cache driver is
 * currently configured (i.e. still the .env default at this point, since we
 * haven't applied the override yet) — that one lookup is cheap and cached for
 * an hour by SettingsRepository, so there's no circularity in practice: the
 * setting that picks the driver is fetched once via the old driver, then
 * everything afterwards uses the new one.
 */
class PerformanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // phpunit.xml pins CACHE_STORE=array and QUEUE_CONNECTION=sync so every
        // run is deterministic. Applying the DB-backed values over the top made
        // the suite depend on whether a `settings` table happened to exist at
        // boot: on an in-memory SQLite database it never does, so those runs
        // kept the pinned drivers, while MySQL and Postgres silently swapped in
        // the `database` cache and queue. That is the whole reason the same
        // suite passed on one driver and failed on the other two — dispatched
        // jobs went to the jobs table instead of running inline, and tagged
        // cache calls hit a store that cannot tag. A test that wants the real
        // drivers sets them itself (see DatabaseCacheStoreTest).
        if ($this->app->runningUnitTests()) {
            return;
        }

        // Before installation there is no usable database — and the host may
        // not even have a database driver enabled yet (the installer's
        // requirements step is what verifies that). Skip entirely so a fresh
        // unzip renders the installer instead of a 500. Calling Schema::hasTable
        // here would itself try to connect and throw "could not find driver".
        if (! Installer::isInstalled()) {
            return;
        }

        if (! Schema::hasTable('settings')) {
            // Fresh install, before the first migration has run.
            return;
        }

        try {
            $settings = PerformanceSettings::get();
        } catch (\Throwable) {
            // Never let a misconfigured/unreachable settings store break boot.
            return;
        }

        config([
            'cache.default' => $settings->cache_driver,
            'queue.default' => $settings->queue_connection,
            'octane.server' => $settings->octane_server,
        ]);

        if (in_array('redis', [$settings->cache_driver, $settings->queue_connection], true)) {
            config([
                'database.redis.default.host' => $settings->redis_host,
                'database.redis.default.port' => $settings->redis_port,
                'database.redis.default.password' => $settings->redis_password,
                'database.redis.default.database' => $settings->redis_database,
            ]);
        }
    }
}
