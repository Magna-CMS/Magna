<?php

declare(strict_types=1);

namespace Magna\Admin;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Magna\Contracts\RegistersAdminResources;
use Magna\Contracts\RegistersDashboardWidgets;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Plugins\Manifest;
use Magna\Plugins\Plugin;
use Magna\Plugins\PluginAutoloader;
use Magna\Plugins\PluginRecord;
use Throwable;

/**
 * The slice of the admin panel that enabled plugins contribute: their Filament
 * resources, settings pages, and dashboard widgets.
 *
 * Lives outside AdminPanelProvider because it runs at an awkward moment and has
 * to be careful about it. The panel is registered during
 * FilamentServiceProvider::boot(), BEFORE PluginsServiceProvider boots the
 * plugins themselves, so PluginManager::getEnabled() is empty here and the
 * plugins table is read directly instead. Every lookup is wrapped: one broken
 * plugin must never stop the panel from rendering.
 */
class PluginPanelSurface
{
    public function __construct(private readonly Application $app) {}

    /**
     * Collect Filament resource classes from active plugins that implement
     * RegistersAdminResources.
     *
     * This is called during the resolving(PanelRegistry) callback, which fires
     * during FilamentServiceProvider::boot() — before PluginsServiceProvider::boot()
     * has run bootEnabledPlugins(). We therefore bypass PluginManager::getEnabled()
     * and query the plugins table directly; DB is available at this point.
     *
     * @return list<class-string>
     */
    public function resources(): array
    {
        return $this->collect(
            RegistersAdminResources::class,
            /** @param Plugin&RegistersAdminResources $plugin */
            static fn (object $plugin): iterable => $plugin->adminResources(),
        );
    }

    /**
     * Collects Filament page classes from plugins that implement RegistersSettingsPages.
     * These are registered in the panel so their routes exist and getUrl() works.
     *
     * @return list<class-string>
     */
    public function pages(): array
    {
        return $this->collect(
            RegistersSettingsPages::class,
            /** @param Plugin&RegistersSettingsPages $plugin */
            static fn (object $plugin): iterable => $plugin->settingsPages(),
        );
    }

    /**
     * Collects Filament widget classes from plugins that implement
     * RegistersDashboardWidgets, so plugin cards appear on the dashboard.
     *
     * @return list<class-string>
     */
    public function widgets(): array
    {
        return $this->collect(
            RegistersDashboardWidgets::class,
            /** @param Plugin&RegistersDashboardWidgets $plugin */
            static fn (object $plugin): iterable => $plugin->dashboardWidgets(),
        );
    }

    /**
     * Ask every enabled plugin implementing $contract for the classes it
     * contributes, one plugin at a time.
     *
     * Isolation is the point. A single try/catch around the whole loop meant
     * one plugin whose entry class threw — a bad manifest, a constructor that
     * touches a table its migration never created — silently cost EVERY plugin
     * after it its admin surface. For pages that is not cosmetic: the classes
     * collected here are what give a plugin's settings pages their routes, so
     * dropping them leaves nav entries and widgets pointing at routes that do
     * not exist, which is a 500 on every admin request. One broken plugin now
     * costs exactly itself.
     *
     * @param  class-string  $contract
     * @param  callable(object): iterable<class-string>  $classes
     * @return list<class-string>
     */
    private function collect(string $contract, callable $classes): array
    {
        try {
            if (! Schema::hasTable('plugins')) {
                return [];
            }

            /** @var Collection<int, PluginRecord> $records */
            $records = PluginRecord::query()
                ->where('enabled', true)
                ->get(['manifest', 'base_path']);
        } catch (Throwable) {
            // No usable database yet (pre-install, or mid-migration) — the
            // panel still has to render.
            return [];
        }

        $collected = [];

        foreach ($records as $record) {
            try {
                $plugin = $this->pluginFor($record, $contract);

                if ($plugin === null) {
                    continue;
                }

                foreach ($classes($plugin) as $class) {
                    $collected[] = $class;
                }
            } catch (Throwable) {
                // This plugin contributes nothing; the rest still do.
            }
        }

        return $collected;
    }

    /**
     * The plugin behind a record, or null when it cannot contribute to this
     * contract (not installed on disk, not autoloadable, does not implement it).
     */
    private function pluginFor(PluginRecord $record, string $contract): ?Plugin
    {
        /** @var array<string, mixed> $manifest */
        $manifest = $record->manifest;
        $entryClass = $manifest['entry'] ?? null;

        // Panel registration runs before PluginManager boots plugins, so a
        // plugin whose files were dropped on disk (a marketplace install, a zip
        // upload) is not autoloadable yet at this point.
        $this->registerAutoload($record);

        if (! is_string($entryClass) || ! class_exists($entryClass)) {
            return null;
        }

        if (! is_a($entryClass, $contract, true)) {
            return null;
        }

        /** @var Plugin $plugin */
        $plugin = $this->app->make($entryClass, [
            'app' => $this->app,
            'basePath' => $record->base_path,
            'manifest' => Manifest::fromArray($manifest),
        ]);

        return $plugin;
    }

    /**
     * Make an installed plugin's classes loadable before this provider asks
     * whether its entry class exists.
     *
     * The panel is registered long before PluginManager boots plugins, and a
     * plugin installed as dropped files — the marketplace's licensed-download
     * path, the Core Plugin Manager's zip upload — is not in Composer's
     * autoload maps at all. Its entry class therefore "does not exist" here,
     * the resolvers below skip it silently, and its pages never get routes
     * while its widgets and nav items still render. Anything that then links
     * to one of those pages dies with "Route [...] not defined" on EVERY
     * admin request, taking the whole panel down with no way back in.
     *
     * PluginAutoloader is a singleton and idempotent, so calling this per
     * record per resolver costs one array lookup after the first time.
     */
    private function registerAutoload(PluginRecord $record): void
    {
        $basePath = $record->base_path;

        if (! is_string($basePath) || $basePath === '') {
            return;
        }

        try {
            $this->app->make(PluginAutoloader::class)->register($basePath);
        } catch (Throwable) {
            // Same contract as the resolvers: never break the panel over one
            // plugin's bad metadata.
        }
    }
}
