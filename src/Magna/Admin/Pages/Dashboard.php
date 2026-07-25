<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Magna\Admin\Widgets\EntryCounts;
use Magna\Admin\Widgets\RecentActivity;
use Magna\Updater\UpdateCheck;
use Magna\Updater\UpdateCheckClient;
use Magna\Users\User;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -1;

    protected string $view = 'magna::admin.dashboard';

    public function mount(): void
    {
        // Keep announcements/updates fresh without depending on the scheduler
        // cron: if the last hub check-in is stale (>12h) or never happened,
        // refresh — deferred past the response so it never slows the dashboard
        // and never breaks it if the hub is unreachable. Gated on the timestamp
        // so the hub is hit at most once per window no matter how many admins
        // load the page.
        app()->terminating(function (): void {
            rescue(function (): void {
                $last = UpdateCheck::query()->max('checked_at');
                if ($last === null || Carbon::parse((string) $last)->lessThanOrEqualTo(now()->subHours(12))) {
                    app(UpdateCheckClient::class)->checkIn();
                }
            }, report: false);
        });
    }

    /** @return list<class-string<Widget>> */
    public function getWidgets(): array
    {
        // All widgets registered on the panel — includes core widgets AND any
        // contributed by plugins via RegistersDashboardWidgets, so new plugins
        // appear automatically with no dashboard changes.
        /** @var list<class-string<Widget>> $all */
        $all = array_values(Filament::getCurrentPanel()?->getWidgets() ?? []);

        if ($all === []) {
            $all = [EntryCounts::class, RecentActivity::class];
        }

        // Order by each widget's declared static $sort (Filament convention), so
        // a plugin widget lands in a sensible position rather than at the bottom.
        usort($all, fn (string $a, string $b): int => $this->widgetSort($a) <=> $this->widgetSort($b));

        /** @var User|null $user */
        $user = auth()->user();
        $savedOrder = $user?->widget_order ?? [];

        if (empty($savedOrder)) {
            return $all;
        }

        // Re-order by saved preference; append any newly added widgets at the end.
        $indexed = collect($all)->keyBy(fn (string $class): string => class_basename($class));

        $sorted = collect($savedOrder)
            ->map(fn (string $key): ?string => $indexed->get($key))
            ->filter()
            ->values()
            ->all();

        $missing = array_values(
            array_filter($all, fn (string $class): bool => ! in_array(class_basename($class), $savedOrder, true))
        );

        return array_merge($sorted, $missing);
    }

    /** Read a widget's declared static $sort (Filament convention); default last. */
    private function widgetSort(string $class): int
    {
        try {
            $reflection = new \ReflectionClass($class);
            if ($reflection->hasProperty('sort')) {
                $property = $reflection->getProperty('sort');
                $value = $property->getValue();

                return is_int($value) ? $value : 999;
            }
        } catch (\Throwable) {
            // Fall through to the default.
        }

        return 999;
    }

    /**
     * The dashboard is laid out as a CSS multi-column masonry (see
     * dashboard.blade.php) — there's no grid, so a widget's own $columnSpan
     * property normally has nothing to attach to. Widgets that declare
     * columnSpan = 'full' get CSS `column-span: all` instead, which breaks
     * them out of the masonry columns for a true full-width row while
     * everything stays in one flat, drag-reorderable container.
     */
    public function widgetIsFullWidth(string $class): bool
    {
        try {
            $reflection = new \ReflectionClass($class);

            if (! $reflection->hasProperty('columnSpan')) {
                return false;
            }

            // Reflected without instantiating: Widget is a Livewire component,
            // and constructing one outside Livewire's own lifecycle is unsafe.
            // getDefaultValue() reads the typed property's declared default,
            // which is all every widget in this codebase sets it from anyway.
            $value = $reflection->getProperty('columnSpan')->getDefaultValue();

            if (is_string($value)) {
                return $value === 'full';
            }

            if (is_array($value)) {
                return ($value['default'] ?? null) === 'full';
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<class-string<Widget>> */
    public function getVisibleWidgets(): array
    {
        return $this->filterVisibleWidgets($this->getWidgets());
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    /**
     * Called by the dashboard blade after the user drags widgets into a new order.
     *
     * @param  list<string>  $order  Array of class_basename keys, e.g. ['RecentActivity', 'EntryCounts']
     */
    public function reorderWidgets(array $order): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        $user?->update(['widget_order' => $order]);
    }
}
