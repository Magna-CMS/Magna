<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Contracts\Events\Dispatcher;
use Magna\Updater\Events\CoreUpdated;
use ReflectionObject;
use Throwable;

/**
 * What every enabled plugin gets told when the core has just changed under it.
 *
 * Three things, in order, under the NEW core: the plugin's own migrations
 * (they used to run only on enable, so a plugin whose schema depends on a
 * newer core never caught up), its `upgrade($from, $to)` hook if it defines
 * one, and finally the CoreUpdated event for anything listening. A plugin
 * that throws is reported, not fatal — the update is already live and one
 * plugin's mistake must not stop the others hearing about it.
 */
final class PluginUpgradeHooks
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PluginMigrator $migrator,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @return array{migrated: list<string>, upgraded: list<string>, failed: list<string>}
     */
    public function run(string $from, string $to): array
    {
        $report = ['migrated' => [], 'upgraded' => [], 'failed' => []];

        foreach (PluginRecord::query()->where('enabled', true)->get() as $record) {
            try {
                $this->migrator->run((string) $record->base_path);
                $report['migrated'][] = $record->name;
            } catch (Throwable $e) {
                $report['failed'][] = $record->name.' (migrations): '.$e->getMessage();
            }
        }

        foreach ($this->plugins->getEnabled() as $name => $plugin) {
            // The hook arrived with SDK 1.6. On a site whose bundled SDK copy
            // predates it — one a core update has not refreshed yet — the base
            // class has no such method, and the plugin is simply not told.
            // Reflection rather than method_exists(): the analyser knows the
            // SDK this checkout has, not the one an older site runs.
            if (! (new ReflectionObject($plugin))->hasMethod('upgrade')) {
                continue;
            }

            try {
                $plugin->upgrade($from, $to);
                $report['upgraded'][] = $name;
            } catch (Throwable $e) {
                $report['failed'][] = $name.' (upgrade): '.$e->getMessage();
            }
        }

        $this->events->dispatch(new CoreUpdated($from, $to));

        return $report;
    }
}
