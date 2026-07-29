<?php

declare(strict_types=1);

namespace Magna\Admin\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;

/**
 * Dashboard widget showing entries scheduled to publish or auto-unpublish.
 */
class UpcomingScheduleWidget extends Widget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = true;

    protected string $view = 'magna::admin.widgets.upcoming-schedule';

    /**
     * What is queued to publish, and when, is editorial information. Same rule
     * as EntryCounts: the dashboard admits anyone with panel access, so the
     * widget decides for itself.
     */
    public static function canView(): bool
    {
        return auth()->user()?->can('content.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        return ['rows' => $this->loadRows()];
    }

    /** @return list<array{label: string, type: string, status: string, locale: string, event: string, at: Carbon}> */
    private function loadRows(): array
    {
        /** @var SchemaRegistry $registry */
        $registry = app(SchemaRegistry::class);

        $rows = [];

        // Cache schema presence checks for 60 s — avoids N hasTable()+hasColumn()
        // calls (each a DB query) on every dashboard load when many types exist.
        $tableList = Cache::remember('magna.widget.schedule.tables', 60, fn () => Schema::getTableListing());

        // Stage 13 (A-2 follow-up): previously listed titles of scheduled/
        // unpublished entries across every content type to any Dashboard
        // viewer regardless of content.{type}.view.
        foreach ($registry->all() as $type) {
            if (! in_array($type->tableName(), $tableList, true)) {
                continue;
            }

            if (! (auth()->user()?->can("content.{$type->handle}.view") ?? false)) {
                continue;
            }

            foreach (
                Entry::type($type->handle)
                    ->where('status', EntryStatus::Scheduled)
                    ->where('published_at', '>', now())
                    ->orderBy('published_at')
                    ->limit(10)
                    ->get() as $entry
            ) {
                $at = $entry->getAttribute('published_at');
                $rows[] = [
                    'label' => (string) ($entry->getAttribute('title') ?? $entry->getAttribute('name') ?? $entry->getKey()),
                    'type' => $type->displayName,
                    'status' => 'publish',
                    'locale' => (string) ($entry->getAttribute('locale') ?? ''),
                    'at' => $at instanceof Carbon ? $at : now(),
                ];
            }

            $hasUnpublishAt = Cache::remember("magna.widget.schedule.has_unpublish_at.{$type->handle}", 300, fn () => Schema::hasColumn($type->tableName(), 'unpublish_at'));
            if ($hasUnpublishAt) {
                foreach (
                    Entry::type($type->handle)
                        ->where('status', EntryStatus::Published)
                        ->whereNotNull('unpublish_at')
                        ->where('unpublish_at', '>', now())
                        ->orderBy('unpublish_at')
                        ->limit(10)
                        ->get() as $entry
                ) {
                    $at = $entry->getAttribute('unpublish_at');
                    $rows[] = [
                        'label' => (string) ($entry->getAttribute('title') ?? $entry->getAttribute('name') ?? $entry->getKey()),
                        'type' => $type->displayName,
                        'status' => 'unpublish',
                        'locale' => (string) ($entry->getAttribute('locale') ?? ''),
                        'at' => $at instanceof Carbon ? $at : now(),
                    ];
                }
            }
        }

        usort($rows, fn (array $a, array $b): int => $a['at']->timestamp <=> $b['at']->timestamp);

        return array_slice($rows, 0, 20);
    }
}
