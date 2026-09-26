<?php

declare(strict_types=1);

namespace Magna\Updater\Footprint;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Magna\MagnaServiceProvider;
use Magna\Plugins\PluginCompatibilityCheck;
use Magna\Support\ConfigDrift;
use Magna\Support\StaleClassMap;
use Magna\Updater\IncompatiblePlugin;
use Magna\Updater\Run\UpdateJournal;
use Magna\Updater\UpdatePaths;
use Throwable;

/**
 * Does this install look like what its release expected?
 *
 * The version constant always arrives, so a site can run 1.4.3 code beside a
 * 1.4.2 layout and report itself up to date — which is exactly what one did.
 * This compares the footprint of record against the running version and the
 * disk, reads the run journal for an update that never finished, and asks
 * the two other places drift hides — the site's own config file and the
 * plugins it has enabled — so System Info can say so and offer the repair.
 * Cheap: a handful of stats and one plugin discovery, on the pages that ask.
 */
final class FootprintCheck
{
    public function __construct(
        private readonly UpdatePaths $paths,
        private readonly InstalledFootprint $footprint,
        private readonly StaleClassMap $staleClassMap,
        private readonly ConfigDrift $configDrift,
        private readonly PluginCompatibilityCheck $plugins,
    ) {}

    /** @return list<array{label: string, help: string}> */
    public function warnings(): array
    {
        return [
            ...$this->pendingRunWarnings(),
            ...$this->footprintWarnings(),
            ...$this->configWarnings(),
            ...$this->pluginWarnings(),
            ...$this->staleClassWarnings(),
        ];
    }

    /** True when this version's delivery was never recorded — an older updater performed it. */
    public function deliveredByOlderUpdater(): bool
    {
        $recorded = $this->footprint->read();

        return $recorded === null || ($recorded['version'] ?? null) !== MagnaServiceProvider::VERSION;
    }

    /** @return list<array{label: string, help: string}> */
    private function pendingRunWarnings(): array
    {
        $journal = UpdateJournal::latestPending($this->paths);

        if ($journal === null) {
            return [];
        }

        $state = $journal->state();
        $to = $journal->string('to') ?? '?';

        if ($state->isPostSwitch()) {
            return [[
                'label' => "The update to v{$to} has switched files but has not finished (run {$journal->runId()}, {$state->value})",
                'help' => 'Migrations and caches still have to run under the new release. Reloading this page resumes it; so does `php artisan magna:core:resume`. To go back instead: `php artisan magna:core:rollback`.',
            ]];
        }

        return [[
            'label' => "An update to v{$to} was started and did not finish (run {$journal->runId()}, {$state->value})",
            'help' => $journal->isAbandoned()
                ? 'Its process is gone. Nothing of the new release is live; `php artisan magna:core:resume` clears it up, or start the update again.'
                : 'It may still be running. If nothing changes for ten minutes, `php artisan magna:core:resume` clears it up.',
        ]];
    }

    /** @return list<array{label: string, help: string}> */
    private function footprintWarnings(): array
    {
        $recorded = $this->footprint->read();
        $version = MagnaServiceProvider::VERSION;

        if ($recorded === null) {
            return [[
                'label' => "v{$version} was delivered by an older updater and nothing recorded what arrived",
                'help' => 'The code is here; whether every other path the release ships arrived with it is unknown. Repair re-applies this release from its archive and records the result: the Repair button above once Update Manager publishes the archive for this version, or `php artisan magna:core:repair --archive=<magna-cms-v'.$version.'.zip> --sha256=<its checksum>`.',
            ]];
        }

        if (($recorded['version'] ?? null) !== $version) {
            $was = is_string($recorded['version'] ?? null) ? $recorded['version'] : '?';

            return [[
                'label' => "v{$version} is running but the last recorded delivery is v{$was}",
                'help' => 'An older updater performed the last update without recording it. Repair re-applies this release and records the result: the Repair button above once Update Manager publishes the archive for this version, or `php artisan magna:core:repair --archive=<magna-cms-v'.$version.'.zip> --sha256=<its checksum>`.',
            ]];
        }

        $missing = [];

        foreach (is_array($recorded['paths'] ?? null) ? $recorded['paths'] : [] as $relative) {
            if (is_string($relative) && ! is_dir($this->paths->base($relative)) && ! is_file($this->paths->base($relative))) {
                $missing[] = $relative;
            }
        }

        if ($missing !== []) {
            return [[
                'label' => 'Core paths recorded as delivered are missing: '.implode(', ', $missing),
                'help' => 'Something removed them after the update. Repair puts them back from the release archive: `php artisan magna:core:repair`.',
            ]];
        }

        return [];
    }

    /**
     * A key the site's file still sets that core has since replaced by name
     * is a setting nothing reads. Said here, and once per version in the log.
     *
     * @return list<array{label: string, help: string}>
     */
    private function configWarnings(): array
    {
        try {
            $report = $this->configDrift->report();
        } catch (Throwable) {
            return [];
        }

        $warnings = [];

        foreach ($report['tombstones_set'] as $old) {
            $new = ConfigDrift::TOMBSTONES[$old] ?? '?';
            $warnings[] = [
                'label' => "config/magna.php still sets `{$old}`, which nothing reads any more",
                'help' => "It was replaced by `{$new}` so that its safe default could reach updated sites. Set the new key if you meant the old one; delete the old line either way.",
            ];
        }

        if ($warnings !== []) {
            try {
                if (Cache::add('magna.config.tombstone-notice.'.MagnaServiceProvider::VERSION, true, now()->addWeek())) {
                    Log::notice('[magna.config] config/magna.php sets replaced keys: '.implode(', ', $report['tombstones_set']));
                }
            } catch (Throwable) {
                // The warning on screen is the point; the log line is a courtesy.
            }
        }

        return $warnings;
    }

    /**
     * Enabled plugins whose manifest rules out the running core. Never
     * disabled for the declaration alone — a plugin is taken down only when
     * it actually throws — but said, because the next update is where the
     * declaration becomes a refusal.
     *
     * @return list<array{label: string, help: string}>
     */
    private function pluginWarnings(): array
    {
        try {
            $incompatible = $this->plugins->incompatibleWithCore(MagnaServiceProvider::VERSION);
        } catch (Throwable) {
            return [];
        }

        if ($incompatible === []) {
            return [];
        }

        $names = array_map(static fn (IncompatiblePlugin $p): string => "{$p->displayName} v{$p->installedVersion} (requires magna {$p->requiredCompat})", $incompatible);

        return [[
            'label' => count($incompatible).' enabled plugin(s) declare no support for v'.MagnaServiceProvider::VERSION.': '.implode(', ', $names),
            'help' => 'They keep running as they are; a plugin is only disabled when it actually fails. Update them to versions that declare support, or the next core update will refuse until they are disabled.',
        ]];
    }

    /** @return list<array{label: string, help: string}> */
    private function staleClassWarnings(): array
    {
        $stale = $this->staleClassMap->stale();

        if ($stale === []) {
            return [];
        }

        return [[
            'label' => count($stale).' autoload entries point at core files a release removed',
            'help' => 'They are disarmed at every boot, so nothing breaks; the map itself is only rebuilt when Composer runs on this server or a release replaces vendor/. Informational.',
        ]];
    }
}
