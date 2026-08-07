<?php

declare(strict_types=1);

namespace Magna\Content\Console;

use Illuminate\Console\Command;
use Magna\Content\SchemaRegistry;
use Magna\Content\TableGenerator;

/**
 * One-time upgrade command (same pattern as magna:content:index): adds the
 * translation_group column to content-type tables created before it existed
 * and backfills groups from the old same-slug locale convention.
 */
class AddTranslationGroupsCommand extends Command
{
    protected $signature = 'magna:content:add-translation-groups';

    protected $description = 'Add and backfill the translation_group column on existing content-type tables.';

    public function handle(SchemaRegistry $registry, TableGenerator $generator): int
    {
        $types = $registry->all();

        if ($types === []) {
            $this->info('No content types registered — nothing to do.');

            return self::SUCCESS;
        }

        foreach ($types as $type) {
            $generator->addTranslationGroupColumn($type);
            $this->info("Backfilled {$type->tableName()}");
        }

        return self::SUCCESS;
    }
}
