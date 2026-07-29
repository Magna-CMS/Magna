<?php

declare(strict_types=1);

namespace Magna\Admin\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Magna\Content\Entry;
use Magna\Content\SchemaRegistry;

class EntryCounts extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    // StatsOverviewWidget defaults columnSpan to 'full' — override back to a
    // normal masonry column so this sits half-width like the other dashboard
    // cards, rather than as a full-width row.
    protected int|string|array $columnSpan = 1;

    // Render a skeleton immediately, then load counts in a deferred request so
    // the dashboard shell appears instantly rather than blocking on queries.
    protected static bool $isLazy = true;

    /**
     * The dashboard itself is open to anyone who may reach the panel, so each
     * widget states its own audience. Content totals are content data: somebody
     * who cannot open a single entry has no business being told how many there
     * are.
     */
    public static function canView(): bool
    {
        return auth()->user()?->can('content.view') ?? false;
    }

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        /** @var SchemaRegistry $registry */
        $registry = app(SchemaRegistry::class);

        $stats = [];

        // Stage 13 (A-2 follow-up): previously showed a row-count per
        // content type to any Dashboard viewer regardless of
        // content.{type}.view — now scoped to types the viewer can
        // actually see, matching EntryResource's own gate.
        foreach ($registry->all() as $type) {
            if (! (auth()->user()?->can("content.{$type->handle}.view") ?? false)) {
                continue;
            }

            try {
                $count = Entry::type($type->handle)->count();
            } catch (\Throwable) {
                // Table may not exist yet for new types; skip gracefully.
                $count = 0;
            }

            $stats[] = Stat::make($type->displayName, (string) $count)
                ->icon('heroicon-o-document-text')
                ->color('primary');
        }

        if ($stats === []) {
            $stats[] = Stat::make('Content types', '0')
                ->icon('heroicon-o-document-text')
                ->description('No content types registered yet.')
                ->color('gray');
        }

        return $stats;
    }
}
