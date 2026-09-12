<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Magna\Content\Models\ContentTypeRecord;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;

/**
 * Owns everything about a plugin's content types: syncing their physical
 * tables on enable, (re-)asserting their content_types records, tearing them
 * down on disable/uninstall, and dropping declared tables on purge.
 *
 * Extracted from PluginManager so the plugin *lifecycle* orchestration and
 * the content-type *persistence* details are separate responsibilities.
 * PluginManager calls these methods from enable()/disable()/uninstall();
 * the transaction boundaries stay in PluginManager (the caller), matching the
 * dependent-write atomicity notes documented there.
 */
final class PluginContentTypeSyncer
{
    public function __construct(private readonly Application $app) {}

    /**
     * Create (or update) the magna_entries_* table for every content type that
     * the plugin declares in its schemas/ directory. Called after enable() loads
     * those schemas into the SchemaRegistry so SchemaSyncer sees them.
     */
    public function syncSchemas(Plugin $plugin): void
    {
        if (! is_dir($plugin->getBasePath().'/schemas')) {
            return;
        }

        if (! $this->app->bound(SchemaSyncer::class)) {
            return;
        }

        /** @var SchemaRegistry $registry */
        $registry = $this->app->make(SchemaRegistry::class);
        /** @var SchemaSyncer $syncer */
        $syncer = $this->app->make(SchemaSyncer::class);
        $syncer->syncAll($registry, allowDestructive: false);
    }

    /**
     * Re-assert content_types records for a plugin's types on enable. Needed
     * because SchemaSyncer skips the upsert when the physical table already
     * exists (e.g. re-enabling after a non-purge uninstall), which would
     * otherwise leave the record missing.
     */
    public function persist(Plugin $plugin): void
    {
        if (! Schema::hasTable('content_types') || ! $this->app->bound(SchemaRegistry::class)) {
            return;
        }

        /** @var SchemaRegistry $registry */
        $registry = $this->app->make(SchemaRegistry::class);

        $handles = $this->ownedContentTypeHandles(
            $plugin->getBasePath(),
            $plugin->getManifest()->toArray(),
        );

        foreach ($handles as $handle) {
            $type = $registry->get($handle);
            if ($type === null) {
                continue;
            }

            ContentTypeRecord::updateOrCreate(
                ['handle' => $type->handle],
                [
                    'display_name' => $type->displayName,
                    'is_database_defined' => false,
                    'schema' => $type->toArray(),
                ],
            );
        }
    }

    /**
     * Remove the content types a plugin owns from the registry and the
     * content_types table so their navigation and resources disappear.
     *
     * Rows only. Dropping the physical magna_entries_* tables is a separate
     * call (dropEntryTables) because the two cannot share a transaction: see
     * that method, and PluginManager::uninstall() for the ordering.
     */
    public function deregister(PluginRecord $record): void
    {
        if (! Schema::hasTable('content_types')) {
            return;
        }

        $registry = $this->app->bound(SchemaRegistry::class)
            ? $this->app->make(SchemaRegistry::class)
            : null;

        foreach ($this->ownedContentTypeHandles($record) as $handle) {
            ContentTypeRecord::query()->where('handle', $handle)->delete();
            $registry?->forget($handle);
        }

        // The mass delete above bypasses Eloquent model events, so the cached
        // content_types row set that SchemaRegistry::loadFromDatabase() reads is
        // not auto-invalidated (that hook fires on model saved/deleted only).
        // Forget it explicitly so a disabled/uninstalled plugin's content types
        // actually disappear on the next load — including on other Octane
        // workers, whose registries re-sync from this shared cache.
        Cache::forget(SchemaRegistry::CONTENT_TYPES_CACHE_KEY);
    }

    /**
     * Drop the magna_entries_* tables belonging to a plugin's content types.
     * Destructive — purge only, and never inside a transaction.
     *
     * Split out of deregister() because MySQL commits the open transaction the
     * moment it sees DDL. A drop that ran alongside the row deletes therefore
     * ended the transaction under everything that followed, and the eventual
     * commit failed with "There is no active transaction" — an uninstall that
     * had in fact done its work reported itself as a failure. Called after the
     * transaction has committed, so the rows are already gone and this drops
     * tables nothing refers to any more.
     */
    public function dropEntryTables(PluginRecord $record): void
    {
        foreach ($this->ownedContentTypeHandles($record) as $handle) {
            Schema::dropIfExists('magna_entries_'.$handle);
        }
    }

    /**
     * Drop the tables a plugin's manifest declares under uninstall.tables,
     * skipping any core Magna table. DDL — same transaction rule as
     * dropEntryTables().
     */
    public function purge(PluginRecord $record): void
    {
        /** @var array<string, mixed> $manifest */
        $manifest = $record->manifest;
        $uninstall = $manifest['uninstall'] ?? null;
        if (! is_array($uninstall)) {
            return;
        }

        $tables = $uninstall['tables'] ?? [];
        if (! is_array($tables)) {
            return;
        }

        // A manifest names tables, but it only gets to drop the ones nothing
        // else owns: never a core table, and never a table another installed
        // plugin claims — a tampered (or merely careless) manifest could
        // otherwise take a different plugin's data with it on the way out.
        $claimed = $this->tablesClaimedByOtherPlugins($record);

        $droppable = [];
        foreach ($tables as $table) {
            if (! is_string($table) || $this->isCoreTable($table)) {
                continue;
            }

            if (isset($claimed[$table])) {
                Log::warning("Plugin [{$record->name}] purge refused to drop [{$table}]: another installed plugin claims it.");

                continue;
            }

            $droppable[] = $table;
        }

        // The manifest lists its tables in whatever order the author wrote
        // them, so a parent almost always precedes the child pointing at it,
        // and MySQL/Postgres refuse to drop a table another table still
        // references. SQLite does not enforce this during a drop, which is why
        // a purge that works in development fails on a real deployment with
        // "Cannot drop table ... referenced by a foreign key constraint".
        // Ordering the list is not a fix (a cycle has no valid order), and
        // SET FOREIGN_KEY_CHECKS=0 is worse: it is connection-scoped state
        // that outlives the request on Octane's persistent connections if a
        // fatal error skips the re-enable, and it silently strips protection
        // from every other table while it is off. Dropping the constraints on
        // the doomed tables themselves is exactly as much as the job needs —
        // and a table OUTSIDE the purge list that still references one of
        // these keeps its constraint, so that drop fails loudly instead of
        // leaving a dangling reference behind.
        $this->dropForeignKeysOn($droppable);

        foreach ($droppable as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Drop every foreign key defined on the given tables so they can be
     * dropped in any order. SQLite is skipped on both counts: it neither
     * blocks a DROP TABLE on inbound references nor supports dropping a
     * foreign key in place.
     *
     * @param  list<string>  $tables
     */
    private function dropForeignKeysOn(array $tables): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $name = $foreignKey['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    Schema::table($table, static function (Blueprint $blueprint) use ($name): void {
                        $blueprint->dropForeign($name);
                    });
                }
            }
        }
    }

    /**
     * Every table some OTHER installed plugin has a claim on: the tables its
     * manifest declares under uninstall.tables, plus the magna_entries_*
     * tables of the content types it owns. The record being purged is already
     * deleted by the time purge() runs, so "every installed plugin" and
     * "every other plugin" are the same query — the key exclusion is belt and
     * braces for any future caller that purges a live record.
     *
     * @return array<string, true>
     */
    private function tablesClaimedByOtherPlugins(PluginRecord $record): array
    {
        $claimed = [];

        foreach (PluginRecord::query()->whereKeyNot($record->getKey())->get() as $other) {
            /** @var array<string, mixed> $manifest */
            $manifest = $other->manifest;

            $uninstall = $manifest['uninstall'] ?? [];
            if (is_array($uninstall) && is_array($uninstall['tables'] ?? null)) {
                foreach ($uninstall['tables'] as $table) {
                    if (is_string($table)) {
                        $claimed[$table] = true;
                    }
                }
            }

            foreach ($this->ownedContentTypeHandles($other) as $handle) {
                $claimed['magna_entries_'.$handle] = true;
            }
        }

        return $claimed;
    }

    /**
     * The content type handles a plugin owns. Authoritative source is the
     * plugin's schemas/ directory; the manifest's provides.contentTypes and
     * uninstall.contentTypes are merged in as a fallback so a plugin can list
     * types it registers programmatically rather than via schema files.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return list<string>
     */
    private function ownedContentTypeHandles(PluginRecord|string $recordOrBasePath, ?array $manifest = null): array
    {
        if ($recordOrBasePath instanceof PluginRecord) {
            $basePath = $recordOrBasePath->base_path;
            $manifest = $recordOrBasePath->manifest;
        } else {
            $basePath = $recordOrBasePath;
        }

        $handles = [];

        foreach (glob($basePath.'/schemas/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['handle']) && is_string($decoded['handle'])) {
                $handles[] = $decoded['handle'];
            }
        }

        $manifest ??= [];
        $provides = $manifest['provides'] ?? [];
        if (is_array($provides) && is_array($provides['contentTypes'] ?? null)) {
            foreach ($provides['contentTypes'] as $handle) {
                if (is_string($handle)) {
                    $handles[] = $handle;
                }
            }
        }

        $uninstall = $manifest['uninstall'] ?? [];
        if (is_array($uninstall) && is_array($uninstall['contentTypes'] ?? null)) {
            foreach ($uninstall['contentTypes'] as $handle) {
                if (is_string($handle)) {
                    $handles[] = $handle;
                }
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * Prevent a tampered plugin manifest from dropping core Magna tables.
     * A compromised manifest could otherwise wipe users, payments, etc.
     */
    private function isCoreTable(string $table): bool
    {
        static $coreTables = [
            'users', 'personal_access_tokens', 'plugins', 'content_types',
            'media', 'media_conversions', 'media_folders', 'revisions',
            'webhook_subscriptions', 'webhook_deliveries', 'admin_action_logs',
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'sessions', 'password_reset_tokens',
        ];

        return in_array($table, $coreTables, true);
    }
}
