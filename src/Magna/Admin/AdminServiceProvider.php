<?php

declare(strict_types=1);

namespace Magna\Admin;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\Widget;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Magna\Admin\Console\NotificationsPruneCommand;
use Magna\Admin\Console\PanelPathCommand;
use Magna\Contracts\RegistersAdminNavigation;
use Magna\Contracts\RegistersDashboardWidgets;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Plugins\PluginManager;
use Throwable;

class AdminServiceProvider extends ServiceProvider
{
    /**
     * Core sidebar groups, in display order. Plugin groups render between
     * Dashboard (ungrouped, always first) and these — the WordPress shape
     * the owner asked for: a freshly installed plugin lands in a
     * predictable block after Dashboard instead of wherever its
     * lowest-sorted item happens to fall, and the core groups hold the end.
     */
    public const CORE_NAVIGATION_GROUP_ORDER = ['Content', 'Access', 'API', 'Settings', 'System'];

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
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        // Wire plugin contracts after all providers have booted.
        $this->app->booted(function (): void {
            $this->wirePluginContracts();
            $this->registerErrorExperience();
        });

        $this->commands([NotificationsPruneCommand::class, PanelPathCommand::class]);

        // Same unbounded-growth concern as magna:audit:prune — the bell's
        // notifications table has no other cleanup mechanism.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('magna:notifications:prune')->daily();
        });
    }

    private function wirePluginContracts(): void
    {
        if (! $this->app->bound(PluginManager::class)) {
            // Even with no plugin system (some test apps), the core groups
            // need a registered order, or each sits wherever its
            // lowest-sorted item lands in Filament's global item sort.
            $this->registerNavigationGroupOrder([]);

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

        // Always, not only when a plugin registered a group: the ORDER is the
        // point, and a site with no nav-registering plugins still needs the
        // core groups held in their fixed positions.
        $this->registerNavigationGroupOrder($navGroups);

        if ($widgets !== [] || $settingPages !== []) {
            $panel = Filament::getPanel('magna');

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

    /**
     * Register the sidebar's group order: plugin groups first (Dashboard is
     * ungrouped and always leads regardless), core groups last, in
     * CORE_NAVIGATION_GROUP_ORDER.
     *
     * Filament positions a REGISTERED group by its index in this list and
     * sends unregistered groups to the end — but with nothing registered, a
     * group sat wherever its lowest-sorted item fell in the global item
     * sort, so a plugin whose page declared no sort (-1) jumped its whole
     * group above Content, and two installs of the same plugins could
     * disagree. Plugin groups are sorted by label here for the same reason:
     * boot order is dependency order, which reshuffles as plugins come and
     * go.
     *
     * A plugin that deliberately files its page under a core group
     * ('System', like the Core Plugin Manager) is untouched: groups match
     * by label, so its items land in the core slot.
     *
     * @param  list<NavigationGroup>  $pluginGroups  from RegistersAdminNavigation
     */
    private function registerNavigationGroupOrder(array $pluginGroups): void
    {
        // One call, once per boot: the panel's navigationGroups() APPENDS to
        // what is registered, so the whole ordered list goes in together.
        Filament::getPanel('magna')->navigationGroups(
            $this->orderedNavigationGroups($pluginGroups),
        );
    }

    /**
     * The full registered-group list: plugin groups alphabetically, then the
     * core groups in their fixed order.
     *
     * @param  list<NavigationGroup>  $pluginGroups  from RegistersAdminNavigation
     * @return list<NavigationGroup>
     */
    private function orderedNavigationGroups(array $pluginGroups): array
    {
        $core = self::CORE_NAVIGATION_GROUP_ORDER;
        $byLabel = [];

        foreach ($pluginGroups as $group) {
            $label = (string) $group->getLabel();

            if (! in_array($label, $core, true)) {
                $byLabel[$label] ??= $group;
            }
        }

        // Groups declared only on plugin page/resource classes (no
        // RegistersAdminNavigation): without a registered entry they would
        // sort to the end, BEHIND the core groups meant to hold it.
        foreach ($this->pluginSurfaceGroupLabels() as $label) {
            if (! in_array($label, $core, true)) {
                $byLabel[$label] ??= NavigationGroup::make($label);
            }
        }

        ksort($byLabel, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            ...array_values($byLabel),
            ...array_map(static fn (string $label): NavigationGroup => NavigationGroup::make($label), $core),
        ];
    }

    /**
     * What a failed admin request tells the operator.
     *
     * In production (the toasts only exist with debug off) every failed
     * Livewire request showed Filament's one generic "Error while loading
     * page", whatever actually happened. The two causes an operator can do
     * something about get their own words: an expired session (419 — the tab
     * sat open past the session lifetime) explains itself and reloads to the
     * login screen via the BODY_END script; maintenance (503 — a core update
     * applying) says the site is briefly down rather than broken. Everything
     * else keeps the generic toast.
     *
     * Registered here, not in AdminPanelProvider::panel(): the notifications
     * and render hooks are read at page render, so post-boot registration is
     * equivalent — and the provider file sits against its line pin.
     */
    private function registerErrorExperience(): void
    {
        Filament::getPanel('magna')
            ->registerErrorNotification(
                title: 'Your session expired',
                body: 'Reloading so you can sign in again…',
                statusCode: 419,
            )
            ->registerErrorNotification(
                title: 'Updating — back in a moment',
                body: 'The site is briefly in maintenance while an update finishes.',
                statusCode: 503,
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): ViewContract => view('magna::filament.session-expired-reload'),
            );
    }

    /**
     * The navigation-group labels of plugin-contributed pages and resources.
     *
     * @return list<string>
     */
    private function pluginSurfaceGroupLabels(): array
    {
        $labels = [];
        $surface = new PluginPanelSurface($this->app);

        foreach ([...$surface->pages(), ...$surface->resources()] as $class) {
            try {
                $label = $class::getNavigationGroup();
            } catch (Throwable) {
                continue;
            }

            if ($label instanceof \UnitEnum) {
                $label = $label instanceof \BackedEnum ? (string) $label->value : $label->name;
            }

            if (is_string($label) && $label !== '' && ! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }
}
