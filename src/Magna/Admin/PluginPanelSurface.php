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
        $resources = [];

        try {
            if (! Schema::hasTable('plugins')) {
                return [];
            }

            /** @var Collection<int, PluginRecord> $records */
            $records = PluginRecord::query()
                ->where('enabled', true)
                ->get(['manifest', 'base_path']);

            foreach ($records as $record) {
                /** @var array<string, mixed> $manifest */
                $manifest = $record->manifest;
                $entryClass = $manifest['entry'] ?? null;

                // Panel registration runs before PluginManager boots plugins,
                // so a plugin whose files were dropped on disk (a marketplace
                // install, a zip upload) is not autoloadable yet at this point.
                $this->registerAutoload($record);

                if (! is_string($entryClass) || ! class_exists($entryClass)) {
                    continue;
                }

                if (! is_a($entryClass, RegistersAdminResources::class, true)) {
                    continue;
                }

                /** @var Plugin&RegistersAdminResources $plugin */
                $plugin = $this->app->make($entryClass, [
                    'app' => $this->app,
                    'basePath' => $record->base_path,
                    'manifest' => Manifest::fromArray($manifest),
                ]);

                foreach ($plugin->adminResources() as $resourceClass) {
                    $resources[] = $resourceClass;
                }
            }
        } catch (Throwable) {
            // A broken plugin must never prevent the admin panel from loading.
        }

        return $resources;
    }

    /**
     * Collects Filament page classes from plugins that implement RegistersSettingsPages.
     * These are registered in the panel so their routes exist and getUrl() works.
     *
     * @return list<class-string>
     */
    public function pages(): array
    {
        $pages = [];

        try {
            if (! Schema::hasTable('plugins')) {
                return [];
            }

            /** @var Collection<int, PluginRecord> $records */
            $records = PluginRecord::query()
                ->where('enabled', true)
                ->get(['manifest', 'base_path']);

            foreach ($records as $record) {
                /** @var array<string, mixed> $manifest */
                $manifest = $record->manifest;
                $entryClass = $manifest['entry'] ?? null;

                // Panel registration runs before PluginManager boots plugins,
                // so a plugin whose files were dropped on disk (a marketplace
                // install, a zip upload) is not autoloadable yet at this point.
                $this->registerAutoload($record);

                if (! is_string($entryClass) || ! class_exists($entryClass)) {
                    continue;
                }

                if (! is_a($entryClass, RegistersSettingsPages::class, true)) {
                    continue;
                }

                /** @var Plugin&RegistersSettingsPages $plugin */
                $plugin = $this->app->make($entryClass, [
                    'app' => $this->app,
                    'basePath' => $record->base_path,
                    'manifest' => Manifest::fromArray($manifest),
                ]);

                foreach ($plugin->settingsPages() as $pageClass) {
                    $pages[] = $pageClass;
                }
            }
        } catch (Throwable) {
            // A broken plugin must never prevent the admin panel from loading.
        }

        return $pages;
    }

    /**
     * Collects Filament widget classes from plugins that implement
     * RegistersDashboardWidgets, so plugin cards appear on the dashboard.
     *
     * @return list<class-string>
     */
    public function widgets(): array
    {
        $widgets = [];

        try {
            if (! Schema::hasTable('plugins')) {
                return [];
            }

            /** @var Collection<int, PluginRecord> $records */
            $records = PluginRecord::query()
                ->where('enabled', true)
                ->get(['manifest', 'base_path']);

            foreach ($records as $record) {
                /** @var array<string, mixed> $manifest */
                $manifest = $record->manifest;
                $entryClass = $manifest['entry'] ?? null;

                // Panel registration runs before PluginManager boots plugins,
                // so a plugin whose files were dropped on disk (a marketplace
                // install, a zip upload) is not autoloadable yet at this point.
                $this->registerAutoload($record);

                if (! is_string($entryClass) || ! class_exists($entryClass)) {
                    continue;
                }

                if (! is_a($entryClass, RegistersDashboardWidgets::class, true)) {
                    continue;
                }

                /** @var Plugin&RegistersDashboardWidgets $plugin */
                $plugin = $this->app->make($entryClass, [
                    'app' => $this->app,
                    'basePath' => $record->base_path,
                    'manifest' => Manifest::fromArray($manifest),
                ]);

                foreach ($plugin->dashboardWidgets() as $widgetClass) {
                    $widgets[] = $widgetClass;
                }
            }
        } catch (Throwable) {
            // A broken plugin must never prevent the admin panel from loading.
        }

        return $widgets;
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
