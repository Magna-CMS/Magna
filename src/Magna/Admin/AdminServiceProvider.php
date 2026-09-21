<?php

declare(strict_types=1);

namespace Magna\Admin;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Widgets\Widget;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
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

                        // ->hidden(), not ->isHidden(): in Filament 5 isHidden()
                        // is the GETTER and ignores arguments, so this closure
                        // was silently discarded and permission-gated nav items
                        // rendered for everyone. Visibility only — the pages
                        // behind the items still authorize via canAccess().
                        $perm = $item->getRequiredPermission();
                        if ($perm !== null) {
                            $navItem->hidden(fn (): bool => ! (auth()->user()?->can($perm) ?? false));
                        }

                        $filamentItems[] = $navItem;
                    }

                    $navGroups[] = NavigationGroup::make($group->label)
                        ->icon($group->icon)
                        ->items($filamentItems);
                }

                // RegistersDashboardWidgets → injected into panel widget list.
                // The contract promises class-strings but not Widget subclasses;
                // anything else would take the whole dashboard down, so it is
                // dropped here — one bad plugin costs exactly itself.
                if ($plugin instanceof RegistersDashboardWidgets) {
                    foreach ($plugin->dashboardWidgets() as $widgetClass) {
                        if (is_a($widgetClass, Widget::class, true)) {
                            $widgets[] = $widgetClass;
                        }
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
                Log::error("Plugin [{$name}] skipped during panel wiring: {$e->getMessage()}");
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
