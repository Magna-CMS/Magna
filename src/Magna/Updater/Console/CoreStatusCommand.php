<?php

declare(strict_types=1);

namespace Magna\Updater\Console;

use Illuminate\Console\Command;
use Magna\MagnaServiceProvider;
use Magna\Support\ConfigDrift;
use Magna\Updater\Footprint\FootprintCheck;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\Run\UpdateResumer;

/**
 * What this install is running, what was recorded as delivered, and whether
 * an update is waiting to be finished — the shell's view of System Info.
 * `--config` adds how far the site's own config/magna.php has drifted from
 * core's defaults.
 *
 * Usage:
 *   php artisan magna:core:status [--config]
 */
class CoreStatusCommand extends Command
{
    protected $signature = 'magna:core:status {--config : Also report how config/magna.php differs from core\'s defaults}';

    protected $description = 'Show the running core version, the recorded delivery, and any unfinished update';

    public function handle(InstalledFootprint $footprint, FootprintCheck $check, UpdateResumer $resumer, ConfigDrift $drift): int
    {
        $this->line('Running:   v'.MagnaServiceProvider::VERSION);

        $recorded = $footprint->read();

        if ($recorded === null) {
            $this->line('Recorded:  nothing — delivered by an older updater, or installed from a checkout');
        } else {
            $this->line(sprintf(
                'Recorded:  v%s via %s at %s (%d path(s))',
                is_string($recorded['version'] ?? null) ? $recorded['version'] : '?',
                is_string($recorded['installed_via'] ?? null) ? $recorded['installed_via'] : '?',
                is_string($recorded['applied_at'] ?? null) ? $recorded['applied_at'] : '?',
                is_array($recorded['paths'] ?? null) ? count($recorded['paths']) : 0,
            ));

            $vendor = $recorded['vendor'] ?? null;

            if (is_array($vendor) && is_string($vendor['decision'] ?? null)) {
                $this->line('Vendor:    '.$vendor['decision'].(is_string($vendor['reason'] ?? null) ? ' — '.$vendor['reason'] : ''));
            }
        }

        $journal = $resumer->pending();

        if ($journal === null) {
            $this->line('Pending:   none');
        } else {
            $this->line(sprintf(
                'Pending:   run %s — v%s to v%s, state %s, last heartbeat %s',
                $journal->runId(),
                $journal->string('from') ?? '?',
                $journal->string('to') ?? '?',
                $journal->state()->value,
                $journal->string('heartbeat_at') ?? '?',
            ));
        }

        if ($this->option('config') === true) {
            $this->reportConfig($drift);
        }

        $warnings = $check->warnings();

        if ($warnings === []) {
            $this->info('No update warnings.');

            return self::SUCCESS;
        }

        foreach ($warnings as $warning) {
            $this->warn($warning['label']);
            $this->line('  '.$warning['help']);
        }

        return self::SUCCESS;
    }

    private function reportConfig(ConfigDrift $drift): void
    {
        $report = $drift->report();

        $this->newLine();
        $this->line('Config:    '.($report['site_file_is_forwarder']
            ? 'config/magna.php hands to core\'s defaults (nothing customised)'
            : 'config/magna.php is customised'));

        if ($report['missing'] !== []) {
            $this->line('  Keys core supplies because the site file lacks them ('.count($report['missing']).'):');

            foreach ($report['missing'] as $key) {
                $this->line('    '.$key);
            }
        }

        foreach ($report['tombstones_set'] as $old) {
            $this->warn('  '.$old.' is still set but nothing reads it; it was replaced by '.(ConfigDrift::TOMBSTONES[$old] ?? '?'));
        }

        $this->newLine();
    }
}
