<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A plugin's own migrations: running them on enable, and forgetting them on a
 * purge so the plugin can be installed again afterwards.
 *
 * Extracted from PluginManager, which owns the lifecycle and delegates each
 * step rather than implementing it — the class was over the 600-line ceiling
 * ArchitectureTest enforces.
 */
class PluginMigrator
{
    public function run(string $basePath): void
    {
        $migrationsPath = $basePath.'/database/migrations';

        if (! is_dir($migrationsPath)) {
            return;
        }

        Artisan::call('migrate', [
            '--path' => $migrationsPath,
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    /**
     * Drops this plugin's rows from the migrations ledger. Only the plugin's
     * own migration files are named, so another plugin's rows are never
     * touched, even if its tables happen to share a prefix.
     *
     * Dropping a plugin's tables while leaving its rows in the ledger makes a
     * purge irreversible in the worst way: enable() re-runs migrate, every
     * migration is already recorded so none of them rebuild the dropped
     * tables, and the plugin can never be installed again on this database.
     *
     * The inverse hazard is just as real — forgetting the ledger while leaving
     * tables behind means the re-run dies on the first CREATE. That is a
     * manifest problem (uninstall.tables must name every table the migrations
     * create) and each plugin's ManifestTest is what guards it.
     *
     * Laravel records migrations by bare filename, so two plugins shipping an
     * identically-named migration file would collide here. Timestamp prefixes
     * make that vanishingly unlikely, and the alternative (recording paths)
     * would mean diverging from the framework's own schema.
     */
    public function forget(string $basePath): void
    {
        $migrationsPath = $basePath.'/database/migrations';

        if (! is_dir($migrationsPath) || ! Schema::hasTable('migrations')) {
            return;
        }

        $names = array_map(
            static fn (string $file): string => basename($file, '.php'),
            glob($migrationsPath.'/*.php') ?: [],
        );

        if ($names === []) {
            return;
        }

        DB::table('migrations')->whereIn('migration', $names)->delete();
    }
}
