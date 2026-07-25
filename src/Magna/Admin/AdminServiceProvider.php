<?php

declare(strict_types=1);

namespace Magna\Admin;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Magna\Admin\Console\NotificationsPruneCommand;
use Magna\Contracts\RegistersAdminNavigation;
use Magna\Contracts\RegistersDashboardWidgets;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Plugins\PluginManager;
use Throwable;

class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(AdminPanelProvider::class);
    }

    public function boot(): void
    {
        // Merge admin views into the existing magna:: namespace.
        // Directory is capital `Resources` (it also holds the PSR-4
        // Magna\Admin\Resources\* classes); the path must match exactly or it
        // silently fails on case-sensitive (Linux) hosts while working on
        // case-insensitive Windows/macOS.
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'magna');

        // Wire plugin contracts after all providers have booted.
        $this->app->booted(function (): void {
            $this->wirePluginContracts();
        });

        $this->commands([NotificationsPruneCommand::class]);

        // Same unbounded-growth concern as magna:audit:prune — the bell's
        // notifications table has no other cleanup mechanism.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('magna:notifications:prune')->daily();
        });
    }

    private function wirePluginContracts(): void
    {
        if (! $this->app->bound(PluginManager::class)) {
            return;
        }

        /** @var PluginManager $manager */
        $manager = $this->app->make(PluginManager::class);

        $navGroups = [];
        $widgets = [];
        $settingPages = [];

        foreach ($manager->getEnabled() as $name => $plugin) {
            try {
                // RegistersAdminNavigation → sidebar nav group
                if ($plugin instanceof RegistersAdminNavigation) {
                    $group = $plugin->adminNavigation();

                    $filamentItems = [];
                    foreach ($group->getItems() as $item) {
                        $navItem = NavigationItem::make($item->label);

                        if ($item->resourceClass !== null) {
                            $navItem->url(
                                fn (): string => $item->resourceClass::getUrl(),
                            );
                        } elseif ($item->route !== null) {
                            $navItem->url(fn (): string => route($item->route));
                        }

                        $perm = $item->getRequiredPermission();
                        if ($perm !== null) {
                            $navItem->isHidden(fn (): bool => ! auth()->user()?->can($perm));
                        }

                        $filamentItems[] = $navItem;
                    }

                    $navGroups[] = NavigationGroup::make($group->label)
                        ->icon($group->icon)
                        ->items($filamentItems);
                }

                // RegistersDashboardWidgets → injected into panel widget list
                if ($plugin instanceof RegistersDashboardWidgets) {
                    foreach ($plugin->dashboardWidgets() as $widgetClass) {
                        $widgets[] = $widgetClass;
                    }
                }

                // RegistersSettingsPages → injected into panel page list
                if ($plugin instanceof RegistersSettingsPages) {
                    foreach ($plugin->settingsPages() as $pageClass) {
                        $settingPages[] = $pageClass;
                    }
                }

            } catch (Throwable $e) {
                // A buggy plugin must not prevent the admin panel from rendering.
                logger()->error("Plugin [{$name}] skipped during panel wiring: {$e->getMessage()}");
            }
        }

        if ($navGroups !== [] || $widgets !== [] || $settingPages !== []) {
            $panel = Filament::getPanel('magna');

            if ($navGroups !== []) {
                $panel->navigationGroups(array_merge(
                    $panel->getNavigationGroups(),
                    $navGroups,
                ));
            }

            if ($widgets !== []) {
                $panel->widgets(array_merge(
                    $panel->getWidgets(),
                    $widgets,
                ));
            }

            if ($settingPages !== []) {
                $panel->pages(array_merge(
                    $panel->getPages(),
                    $settingPages,
                ));
            }
        }
    }
}
