<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The `plugins` table, read and written on PluginManager's behalf.
 *
 * That table — not discovery — is what bootEnabledPlugins() reads, so it is the
 * real answer to "what is installed here". Discovery only says what could be
 * found on disk right now, which is a narrower and more fragile claim: it skips
 * plugins-dev/ entirely in production, and otherwise only reads directories
 * wired in as Composer path repositories.
 *
 * Extracted from PluginManager, which orchestrates the lifecycle rather than
 * implementing each step — and which ArchitectureTest holds to 600 lines.
 */
class PluginRegistry
{
    public function __construct(private readonly PluginDiscovery $discovery) {}

    /**
     * What the table already knows about a plugin, shaped as discovery would
     * have described it.
     *
     * Null when there is no row, or when its manifest no longer parses: a
     * missing plugin and a corrupt one both belong to the caller's
     * PluginNotFoundException, not to a half-built PluginInfo.
     */
    public function find(string $name): ?PluginInfo
    {
        if (! Schema::hasTable('plugins')) {
            return null;
        }

        /** @var PluginRecord|null $record */
        $record = PluginRecord::query()->where('name', $name)->first();

        if ($record === null) {
            return null;
        }

        try {
            return new PluginInfo(Manifest::fromArray($record->manifest), $record->base_path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ensure every discovered plugin has a row, so it shows up in the admin
     * plugin list.
     *
     * Plugins that ship pre-bundled in vendor/ are discoverable but never went
     * through the install flow that writes a PluginRecord, so without this they
     * are invisible and can never be enabled. New rows are created DISABLED — a
     * bundled plugin still requires an explicit, deliberate enable, since it
     * runs with full application access. Existing rows are left untouched:
     * enabled state, timestamps and version are never overwritten here.
     */
    public function syncDiscovered(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        $existing = PluginRecord::query()->pluck('name')->all();

        foreach ($this->discovery->discover() as $info) {
            if (in_array($info->manifest->name, $existing, true)) {
                continue;
            }

            // firstOrCreate + swallowing a duplicate-key race keeps this safe
            // when two admin page loads hit it concurrently (the `name` column
            // is unique): the loser simply no-ops instead of 500ing. Existing
            // rows are never touched, so an enabled plugin is never re-disabled.
            try {
                PluginRecord::firstOrCreate(
                    ['name' => $info->manifest->name],
                    [
                        'display_name' => $info->manifest->displayName,
                        'version' => $info->manifest->version,
                        'enabled' => false,
                        'base_path' => $info->basePath,
                        'manifest' => $info->manifest->toArray(),
                    ],
                );
            } catch (UniqueConstraintViolationException) {
                // Created concurrently by another request — nothing to do.
            }
        }
    }
}
