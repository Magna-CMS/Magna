<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Drops the framework caches that go stale when the set of enabled plugins
 * changes: Filament's component manifest and Laravel's route table.
 * Extracted from PluginManager (per the collaborator pattern) — cache
 * invalidation is its own concern, called identically from enable, disable
 * and uninstall. Best-effort throughout: a caching failure must never block
 * the plugin operation that asked for it.
 */
class PluginCacheInvalidator
{
    public function __construct(private readonly Application $app) {}

    /**
     * Drop Filament's cached component manifest so a plugin's admin
     * resources, pages, and widgets are re-discovered on the next request.
     * Without this, a warm cache (from `filament:cache-components`, common
     * in production) keeps serving the panel surface from before the plugin
     * changed — the new pages and settings simply never appear.
     */
    public function invalidate(): void
    {
        try {
            $cached = $this->app->bootstrapPath('cache/filament');

            // Only bother when a panel manifest was actually cached.
            $manifests = glob($cached.'/panels/*.php');
            if (is_array($manifests) && $manifests !== []) {
                Artisan::call('filament:clear-cached-components');
            }
        } catch (Throwable) {
            // No Filament cache command available, or nothing to clear — ignore.
        }

        $this->invalidateRouteCache();
    }

    /**
     * Drop the cached route table after a plugin's routes change.
     *
     * Production installs run `route:cache`, and a cached route table is built
     * once from whatever was enabled at deploy time — Laravel skips route
     * registration entirely while it exists, so a plugin enabled afterwards
     * gets no routes at all. Its nav items and dashboard widgets still appear
     * (those are read from the plugins table on every request), and the first
     * one that resolves a page URL throws
     *
     *     Route [filament.magna.pages.…] not defined.
     *
     * on EVERY admin request — locking the admin out of the very page they
     * would disable the plugin from. Clearing costs a little per-request route
     * building until the next deploy re-caches; that beats a dead panel.
     */
    private function invalidateRouteCache(): void
    {
        try {
            $app = $this->app;

            // routesAreCached() is on the concrete application, not the contract.
            if ($app instanceof LaravelApplication && $app->routesAreCached()) {
                Artisan::call('route:clear');
            }
        } catch (Throwable) {
            // Read-only bootstrap/cache, no console kernel — never block the
            // enable/disable that asked for this.
        }
    }
}
