<?php

declare(strict_types=1);

namespace Magna\Admin\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Magna\Support\Bytes;

class MediaStatsWidget extends Widget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'magna::admin.widgets.media-stats';

    // Stage 13 (A-2 follow-up): previously showed aggregate media counts,
    // per-mime-type storage usage, and server disk capacity/free-space to
    // any Dashboard viewer regardless of media.view.
    public static function canView(): bool
    {
        return auth()->user()?->can('media.view') ?? false;
    }

    /**
     * The currently selected category card (null = show everything). Drives the
     * active-card highlight and, via the dispatched event, the gallery filter on
     * the ListMedia page.
     */
    public ?string $activeCategory = null;

    public function selectCategory(string $category): void
    {
        // Toggle: clicking the active card again clears the filter.
        $this->activeCategory = $this->activeCategory === $category ? null : $category;

        $this->dispatch('media-category-selected', category: $this->activeCategory);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $rows = DB::table('magna_media')
            ->whereNull('deleted_at')
            ->select('mime_type', DB::raw('COUNT(*) as c'), DB::raw('SUM(size) as b'))
            ->groupBy('mime_type')
            ->get();

        // Four fixed buckets, in display order.
        $categories = [
            'images' => ['label' => 'Images', 'count' => 0, 'bytes' => 0],
            'pdf' => ['label' => 'PDF Documents', 'count' => 0, 'bytes' => 0],
            'video' => ['label' => 'Videos', 'count' => 0, 'bytes' => 0],
            'others' => ['label' => 'Others', 'count' => 0, 'bytes' => 0],
        ];

        foreach ($rows as $row) {
            $mime = (string) $row->mime_type;
            $key = match (true) {
                str_starts_with($mime, 'image/') => 'images',
                $mime === 'application/pdf' => 'pdf',
                str_starts_with($mime, 'video/') => 'video',
                default => 'others',
            };
            $categories[$key]['count'] += (int) $row->c;
            $categories[$key]['bytes'] += (int) $row->b;
        }

        $totalBytes = (int) array_sum(array_column($categories, 'bytes'));
        $totalCount = (int) array_sum(array_column($categories, 'count'));

        // Percentage of used space per bucket, normalised to sum to 100%.
        foreach ($categories as $key => $cat) {
            $categories[$key]['pct'] = $totalBytes > 0 ? (int) round($cat['bytes'] / $totalBytes * 100) : 0;
            $categories[$key]['sizeText'] = Bytes::human($cat['bytes']);
        }

        $sumPct = (int) array_sum(array_column($categories, 'pct'));
        if ($sumPct !== 100 && $totalBytes > 0) {
            $largest = array_key_first($categories);
            foreach ($categories as $key => $cat) {
                if ($cat['bytes'] > $categories[$largest]['bytes']) {
                    $largest = $key;
                }
            }
            $categories[$largest]['pct'] += 100 - $sumPct;
        }

        // Server disk figures (bytes). false when unavailable (e.g. restricted host).
        $diskTotal = @disk_total_space(base_path());
        $diskFree = @disk_free_space(base_path());
        $hasDisk = is_float($diskTotal) && is_float($diskFree) && $diskTotal > 0;

        return [
            'categories' => $categories,
            'activeCategory' => $this->activeCategory,
            'totalUsedText' => Bytes::human($totalBytes),
            'totalCount' => $totalCount,
            'hasDisk' => $hasDisk,
            'capacityText' => $hasDisk ? Bytes::human($diskTotal) : null,
            'availableText' => $hasDisk ? Bytes::human($diskFree) : null,
            'utilizationPct' => $hasDisk ? number_format($totalBytes / $diskTotal * 100, 2) : null,
        ];
    }
}
