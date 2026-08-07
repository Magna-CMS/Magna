<?php

declare(strict_types=1);

namespace Magna\Content\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Magna\Content\SchemaRegistry;
use Magna\Settings\ContentSettings;

class RevisionsPruneCommand extends Command
{
    protected $signature = 'magna:revisions:prune
                            {--keep= : Maximum number of unlabeled revisions to keep per entry (defaults to the content.revision_limit setting)}';

    protected $description = 'Prune old revisions (labeled revisions are kept) and sweep revisions orphaned by deleted entries.';

    public function handle(SchemaRegistry $registry): int
    {
        $keepOption = $this->option('keep');
        $keep = $keepOption !== null
            ? max(1, (int) $keepOption)
            : max(1, ContentSettings::get()->revision_limit);

        $pruned = $this->pruneByCount($keep);
        $swept = $this->sweepOrphans($registry);

        $this->info("Pruned {$pruned} revision(s); swept {$swept} orphaned revision(s).");

        return self::SUCCESS;
    }

    /**
     * Keep the newest N revisions per entry, counting and evicting only
     * UNLABELED rows — a user-named milestone ("before redesign") must never
     * be silently evicted by routine save churn
     * (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §A4).
     */
    private function pruneByCount(int $keep): int
    {
        $groups = DB::table('magna_revisions')
            ->selectRaw('entry_type, entry_id, COUNT(*) as total')
            ->whereNull('label')
            ->groupBy('entry_type', 'entry_id')
            ->havingRaw('COUNT(*) > ?', [$keep])
            ->get();

        $pruned = 0;

        foreach ($groups as $group) {
            /** @var object{entry_type: string, entry_id: string} $group */
            $keepIds = DB::table('magna_revisions')
                ->where('entry_type', $group->entry_type)
                ->where('entry_id', $group->entry_id)
                ->whereNull('label')
                ->orderByDesc('created_at')
                ->limit($keep)
                ->pluck('id');

            $pruned += DB::table('magna_revisions')
                ->where('entry_type', $group->entry_type)
                ->where('entry_id', $group->entry_id)
                ->whereNull('label')
                ->whereNotIn('id', $keepIds)
                ->delete();
        }

        return $pruned;
    }

    /**
     * Delete revisions whose entry no longer exists — hard-deleted entries
     * previously left up to N full-document snapshots behind forever.
     *
     * Only types currently registered (with an existing table) are swept:
     * revisions for an unregistered type may belong to a temporarily
     * disabled plugin and must be tolerated, not destroyed.
     */
    private function sweepOrphans(SchemaRegistry $registry): int
    {
        $swept = 0;

        $entryTypes = DB::table('magna_revisions')
            ->distinct()
            ->pluck('entry_type');

        foreach ($entryTypes as $handle) {
            if (! is_string($handle)) {
                continue;
            }

            $type = $registry->get($handle);
            if ($type === null || ! Schema::hasTable($type->tableName())) {
                continue;
            }

            $swept += DB::table('magna_revisions')
                ->where('entry_type', $handle)
                ->whereNotExists(function ($query) use ($type): void {
                    $query->selectRaw('1')
                        ->from($type->tableName())
                        ->whereColumn($type->tableName().'.id', 'magna_revisions.entry_id');
                })
                ->delete();
        }

        return $swept;
    }
}
