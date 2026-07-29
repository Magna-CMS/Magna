<?php

declare(strict_types=1);

namespace Magna\Plugins\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Magna\Plugins\DependencyResolver;
use Magna\Plugins\Exceptions\DependencyException;
use Magna\Plugins\Exceptions\InvalidManifestException;
use Magna\Plugins\Manifest;
use Magna\Plugins\PluginDiscovery;
use Magna\Plugins\PluginRecord;

/**
 * Diagnose the health of currently-enabled plugins at runtime. Where
 * `magna:plugin:validate` checks static/discovered manifests (release/CI),
 * doctor checks live installed state: files present, entry class loadable,
 * stored manifest parseable, dependency graph still satisfied, and whether the
 * on-disk version has drifted from what was recorded. Errors and warnings are
 * reported separately; exit is non-zero only on errors.
 */
class PluginDoctorCommand extends Command
{
    protected $signature = 'magna:plugin:doctor {name? : Diagnose only this enabled plugin}';

    protected $description = 'Diagnose the health of enabled plugins (files, autoload, dependencies, drift).';

    public function handle(PluginDiscovery $discovery, DependencyResolver $resolver): int
    {
        /** @var array<string, PluginRecord> $records */
        $records = PluginRecord::query()->where('enabled', true)->get()->keyBy('name')->all();

        $name = $this->argument('name');
        if (is_string($name) && ! isset($records[$name])) {
            $this->error("Plugin \"{$name}\" is not enabled.");

            return self::FAILURE;
        }

        if ($records === []) {
            $this->info('No enabled plugins to diagnose.');

            return self::SUCCESS;
        }

        $discovered = [];
        foreach ($discovery->discover() as $info) {
            $discovered[$info->manifest->name] = $info->manifest->version;
        }

        $enabledManifests = $this->parseManifests($records);

        /** @var list<string> $targets */
        $targets = is_string($name) ? [$name] : array_keys($records);

        $errors = 0;
        $warnings = 0;

        foreach ($targets as $target) {
            $record = $records[$target];
            $problems = $this->diagnose($record, $discovered);

            if ($problems['errors'] === [] && $problems['warnings'] === []) {
                $this->line("<info>✓</info> {$target}");

                continue;
            }

            $this->line("<comment>{$target}</comment>");
            foreach ($problems['errors'] as $message) {
                $this->line("  <fg=red>error</> {$message}");
                $errors++;
            }
            foreach ($problems['warnings'] as $message) {
                $this->line("  <fg=yellow>warning</> {$message}");
                $warnings++;
            }
        }

        // Dependency graph across the whole enabled set (missing/version/conflict/cycle).
        try {
            $resolver->resolveBootOrder($enabledManifests);
        } catch (DependencyException $e) {
            $this->line('<comment>dependency graph</comment>');
            $this->line('  <fg=red>error</> '.$e->getMessage());
            $errors++;
        }

        $this->newLine();
        $this->line("Errors: {$errors}   Warnings: {$warnings}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, PluginRecord>  $records
     * @return array<string, Manifest>
     */
    private function parseManifests(array $records): array
    {
        $manifests = [];
        foreach ($records as $name => $record) {
            try {
                $manifests[$name] = Manifest::fromArray($record->manifest);
            } catch (InvalidManifestException) {
                // Reported per-plugin in diagnose().
            }
        }

        return $manifests;
    }

    /**
     * @param  array<string, string>  $discoveredVersions
     * @return array{errors: list<string>, warnings: list<string>}
     */
    private function diagnose(PluginRecord $record, array $discoveredVersions): array
    {
        $errors = [];
        $warnings = [];

        if (! is_dir($record->base_path)) {
            $errors[] = "Base path is missing: {$record->base_path}. The plugin files were moved or deleted — uninstall it, or restore the files.";

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        try {
            $manifest = Manifest::fromArray($record->manifest);
        } catch (InvalidManifestException $e) {
            $errors[] = 'Stored manifest is invalid: '.$e->getMessage();

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        if (! class_exists($manifest->entryClass)) {
            $errors[] = "Entry class [{$manifest->entryClass}] cannot be autoloaded. Run \"composer dump-autoload\" or check the plugin's namespace.";
        }

        foreach ($this->pendingMigrations($record->base_path) as $pending) {
            $warnings[] = "Migration \"{$pending}\" has not been applied. Run \"php artisan migrate\".";
        }

        $onDisk = $discoveredVersions[$record->name] ?? null;
        if ($onDisk === null) {
            $warnings[] = 'Enabled but no longer discovered — it may have been removed from Composer wiring.';
        } elseif ($onDisk !== $record->version) {
            $warnings[] = "On-disk version ({$onDisk}) differs from the enabled version ({$record->version}). Re-enable or upgrade to sync.";
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Migration files under the plugin that are not recorded in Laravel's
     * `migrations` table — i.e. genuinely unapplied. Reads the migration
     * repository directly, so it reports only facts (no guessing). A plugin
     * without migrations, or before the migrations table exists, yields none.
     *
     * @return list<string>
     */
    private function pendingMigrations(string $basePath): array
    {
        $dir = $basePath.'/database/migrations';
        if (! is_dir($dir) || ! Schema::hasTable('migrations')) {
            return [];
        }

        $files = glob($dir.'/*.php') ?: [];
        if ($files === []) {
            return [];
        }

        /** @var list<string> $applied */
        $applied = DB::table('migrations')->pluck('migration')->all();
        $appliedSet = array_flip($applied);

        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (! isset($appliedSet[$name])) {
                $pending[] = $name;
            }
        }

        return $pending;
    }
}
