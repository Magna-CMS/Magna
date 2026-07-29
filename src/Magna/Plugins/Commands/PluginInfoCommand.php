<?php

declare(strict_types=1);

namespace Magna\Plugins\Commands;

use Illuminate\Console\Command;
use Magna\Plugins\Manifest;
use Magna\Plugins\PluginDiscovery;
use Magna\Plugins\PluginRecord;

/**
 * Show everything known about one plugin: manifest metadata, its dependencies,
 * the plugins that depend on it, declared permissions, and its current
 * installed/enabled status.
 */
class PluginInfoCommand extends Command
{
    protected $signature = 'magna:plugin:info {name : The vendor/package name}';

    protected $description = 'Show metadata, dependencies and status for a plugin.';

    public function handle(PluginDiscovery $discovery): int
    {
        $name = (string) $this->argument('name');

        /** @var array<string, Manifest> $manifests */
        $manifests = [];
        foreach ($discovery->discover() as $info) {
            $manifests[$info->manifest->name] = $info->manifest;
        }

        $manifest = $manifests[$name] ?? null;
        if ($manifest === null) {
            $this->error("Plugin \"{$name}\" was not discovered.");

            return self::FAILURE;
        }

        $record = PluginRecord::query()->where('name', $name)->first();
        $status = $record === null ? 'not installed' : ($record->enabled ? 'enabled' : 'disabled');

        $this->components->twoColumnDetail('<info>Name</info>', $manifest->name);
        $this->components->twoColumnDetail('Display name', $manifest->displayName);
        $this->components->twoColumnDetail('Version', $manifest->version);
        $this->components->twoColumnDetail('Author', $manifest->author);
        $this->components->twoColumnDetail('License', $manifest->license);
        $this->components->twoColumnDetail('Magna compat', $manifest->magnaCompat);
        $this->components->twoColumnDetail('PHP compat', $manifest->phpCompat);
        $this->components->twoColumnDetail('Status', $status);

        $this->renderMap('Requires', $manifest->requires);
        $this->renderMap('Conflicts with', $manifest->conflict);
        $this->renderList('Permissions', $manifest->permissions);

        $dependents = [];
        foreach ($manifests as $other => $otherManifest) {
            if (isset($otherManifest->requires[$name])) {
                $dependents[] = $other.' ('.$otherManifest->requires[$name].')';
            }
        }
        $this->renderList('Dependents', $dependents);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $map
     */
    private function renderMap(string $heading, array $map): void
    {
        if ($map === []) {
            return;
        }

        $this->newLine();
        $this->line("<comment>{$heading}</comment>");
        foreach ($map as $key => $value) {
            $this->components->twoColumnDetail("  {$key}", $value);
        }
    }

    /**
     * @param  list<string>  $items
     */
    private function renderList(string $heading, array $items): void
    {
        if ($items === []) {
            return;
        }

        $this->newLine();
        $this->line("<comment>{$heading}</comment>");
        foreach ($items as $item) {
            $this->line("  • {$item}");
        }
    }
}
