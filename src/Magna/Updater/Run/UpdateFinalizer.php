<?php

declare(strict_types=1);

namespace Magna\Updater\Run;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Magna\Admin\Notifications\NotificationRecipients;
use Magna\MagnaServiceProvider;
use Magna\Plugins\PluginCompatibilityCheck;
use Magna\Plugins\PluginUpgradeHooks;
use Magna\Support\StaleClassMap;
use Magna\Updater\CoreUpdateProgress;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\Engine\PathGuard;
use Magna\Updater\Engine\StagedSwap;
use Magna\Updater\Events\CoreUpdateFailed;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\IncompatiblePlugin;
use Magna\Updater\MaintenanceWindow;
use Magna\Updater\PluginDisableResult;
use Magna\Updater\RuntimeRefresh;
use Magna\Updater\UpdateHousekeeping;
use Magna\Updater\UpdatePaths;
use Magna\Updater\UpdateRunLog;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * The second half of an update, run by the release that was just switched in.
 *
 * The old side stops the moment the switch is complete: it is the previous
 * release's code, and every step from here — proving the new code boots,
 * migrating, telling plugins, clearing and re-warming caches, committing the
 * switch, lifting maintenance mode — belongs to the new one. Migrations in
 * particular used to run under the OLD framework classes against the NEW
 * migration files, and before anything had proven the new code could start
 * at all; now the boot check comes first, and a release that fails it is
 * renamed back with the database untouched.
 *
 * Reached through UpdateResumer from whichever process gets there first: the
 * admin's poll, a fresh queue worker, the scheduler's minute tick, a shell.
 * Idempotent per step, so a process that dies partway is picked up where it
 * stopped.
 */
final class UpdateFinalizer
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly UpdatePaths $paths,
        private readonly PathGuard $guard,
        private readonly InstalledFootprint $footprint,
        private readonly MaintenanceWindow $maintenance,
        private readonly RuntimeRefresh $refresh,
        private readonly RunRollback $rollback,
        private readonly UpdateHousekeeping $housekeeping,
        private readonly PluginCompatibilityCheck $compatibility,
        private readonly PluginUpgradeHooks $pluginHooks,
        private readonly Dispatcher $events,
        private readonly UpdateRunLog $log,
    ) {}

    public function finalize(UpdateJournal $journal): RunOutcome
    {
        $to = ltrim($journal->string('to') ?? '', 'vV');

        // The one check that must come before anything: is this process
        // actually running the release it is about to finish? A worker that
        // has not restarted, or a request whose opcache has not noticed, is
        // still the old code and must leave this to a process that is not.
        if ($to === '' || version_compare(MagnaServiceProvider::VERSION, $to, '!=')) {
            return RunOutcome::staleCode(MagnaServiceProvider::VERSION, $to);
        }

        if (! $journal->acquire()) {
            return RunOutcome::busy();
        }

        $this->log->resume($journal->runId());

        try {
            return $this->runSteps($journal, $to);
        } finally {
            $journal->release();
        }
    }

    private function runSteps(UpdateJournal $journal, string $to): RunOutcome
    {
        $reached = self::rank($journal->state());
        $from = ltrim($journal->string('from') ?? '', 'vV');
        $this->bumpAttempts($journal);

        if ($reached < self::rank(RunState::BootHealthy)) {
            $this->progress('Checking the new release boots…', 82, $to);
            $problems = $this->bootProblems($journal);

            if ($problems !== []) {
                return $this->rollback->rollBack($journal, 'the new release failed its boot check: '.implode(' ', $problems));
            }

            $journal->transition(RunState::BootHealthy);
            $this->log->line('Boot check passed under v'.$to.'.');
        }

        if ($reached < self::rank(RunState::Migrated)) {
            $this->progress('Running migrations…', 88, $to);

            try {
                Artisan::call('migrate', ['--force' => true]);
            } catch (Throwable $e) {
                return $this->needsAttention($journal, $from, $to, 'a migration failed: '.$e->getMessage().' The new release is live and boots; fix the cause and run `php artisan magna:core:resume` to retry, or restore your database backup and run `php artisan magna:core:rollback`.');
            }

            // A forced update carries the plugins it knew were incompatible;
            // they are disabled here, under the new code and before the site
            // comes up, so none of them boots against a core it refused.
            $toDisable = $this->strings($journal->get('disable_plugins'));
            if ($toDisable !== []) {
                $result = $this->compatibility->disableNamed($toDisable);
                $journal->set(['disabled_plugins' => $result->disabled, 'undisabled_plugins' => $result->failed]);
                $this->log->line('Disabled incompatible plugins: '.implode(', ', $result->disabled).($result->failed === [] ? '' : '; could not disable: '.implode(', ', $result->failed)));
            }

            $journal->transition(RunState::Migrated);
        }

        if ($reached < self::rank(RunState::PluginsNotified)) {
            $this->progress('Telling plugins…', 91, $to);
            $report = $this->pluginHooks->run($from, $to);
            $journal->set(['plugin_hooks' => $report]);

            if ($report['failed'] !== []) {
                $this->log->line('Plugin hooks reported problems: '.implode('; ', $report['failed']));
            }

            $journal->transition(RunState::PluginsNotified);
        }

        if ($reached < self::rank(RunState::CachesCleared)) {
            $this->progress('Refreshing caches…', 94, $to);
            $this->refresh->clearCaches();
            $this->rewarmCaches($journal);
            $this->primeStaleClassMap($journal, $to);
            $journal->transition(RunState::CachesCleared);
        }

        if ($reached < self::rank(RunState::Committed)) {
            $this->progress('Committing…', 97, $to);
            (new StagedSwap($this->files, $this->paths, $this->guard, $journal))->commit();

            if (! $this->footprint->promote()) {
                $this->log->line('No footprint draft to promote; recording the delivery from the journal.');
                $this->recordFromJournal($journal, $to);
            }

            $this->rollback->disarmBootGuard();
            $journal->transition(RunState::Committed);
        }

        $this->maintenance->leave();
        $this->refresh->reloadOctane();

        $message = (new PluginDisableResult(
            $this->strings($journal->get('disabled_plugins')),
            $this->strings($journal->get('undisabled_plugins')),
        ))->describe($journal->string('mode') === 'repair' ? "Repaired v{$to}." : "Updated to v{$to}.");

        $journal->transition(RunState::Completed, ['outcome' => $message, 'completed_at' => date('c')]);
        CoreUpdateProgress::set(CoreUpdateState::Completed, $message, 100, $to);
        $this->log->line($message);

        $this->warnAboutIncompatiblePlugins($to);
        $this->housekeeping->pruneRuns();
        $this->housekeeping->pruneBackups();

        return RunOutcome::completed($message);
    }

    /**
     * What the release said to check, checked under its own code: the files
     * it promised exist, the classes it named load. The version itself is
     * proven by the fact that this code is running at all.
     *
     * @return list<string>
     */
    private function bootProblems(UpdateJournal $journal): array
    {
        $problems = [];

        foreach ($this->strings($journal->get('check_files')) as $relative) {
            if (! is_file($this->paths->base($relative))) {
                $problems[] = "{$relative} is missing.";
            }
        }

        foreach ($this->strings($journal->get('check_classes')) as $class) {
            try {
                if (! class_exists($class) && ! interface_exists($class)) {
                    $problems[] = "{$class} cannot be loaded.";
                }
            } catch (Throwable $e) {
                $problems[] = "{$class} cannot be loaded: ".$e->getMessage();
            }
        }

        return $problems;
    }

    /** Only what was warm before the switch, so a site that never cached is not made to. */
    private function rewarmCaches(UpdateJournal $journal): void
    {
        $caches = $journal->get('caches', []);
        $caches = is_array($caches) ? $caches : [];

        foreach (['config' => 'config:cache', 'routes' => 'route:cache'] as $key => $command) {
            if (($caches[$key] ?? false) !== true) {
                continue;
            }

            try {
                Artisan::call($command);
                $this->log->line("`{$command}` re-warmed.");
            } catch (Throwable $e) {
                $this->log->line("`{$command}` could not re-warm: ".$e->getMessage());
            }
        }
    }

    private function primeStaleClassMap(UpdateJournal $journal, string $to): void
    {
        $removed = $this->strings($journal->get('removed_classes'));

        if ($removed === []) {
            return;
        }

        (new StaleClassMap(
            $this->paths->storage('framework/cache/magna-stale-classmap.json'),
            $this->paths->base('vendor/composer/autoload_classmap.php'),
            $to,
        ))->prime($removed);
    }

    private function recordFromJournal(UpdateJournal $journal, string $to): void
    {
        $delivered = array_keys(array_filter(
            $journal->paths(),
            static fn (array $info): bool => in_array($info['state'] ?? null, ['swapped', 'committed'], true),
        ));

        $this->footprint->record(
            $to,
            $journal->string('mode') ?? 'update',
            $journal->runId(),
            null,
            $delivered,
        );
    }

    /**
     * A plugin that declares it does not support the release now running is
     * said out loud — logged, and put on every super-admin's bell — never
     * disabled for the declaration alone. It boots as before; only a plugin
     * that actually throws is taken down, by PluginManager.
     */
    private function warnAboutIncompatiblePlugins(string $to): void
    {
        try {
            $incompatible = $this->compatibility->incompatibleWithCore($to);
        } catch (Throwable $e) {
            $this->log->line('Could not check plugin compatibility: '.$e->getMessage());

            return;
        }

        if ($incompatible === []) {
            return;
        }

        $names = array_map(static fn (IncompatiblePlugin $p): string => "{$p->displayName} (requires magna {$p->requiredCompat})", $incompatible);
        $body = 'Enabled plugins that declare no support for v'.$to.': '.implode(', ', $names).'. They keep running; update them or disable them from Plugins.';

        Log::warning('[magna.updater] '.$body);
        $this->log->line($body);

        try {
            NotificationRecipients::notifyDashboard('Plugins incompatible with v'.$to, $body, 'warning');
        } catch (Throwable) {
            // The bell is best-effort; the log line above is the record.
        }
    }

    private function needsAttention(UpdateJournal $journal, string $from, string $to, string $reason): RunOutcome
    {
        $message = "The update to v{$to} is not finished: {$reason}";

        $journal->transition(RunState::NeedsAttention, ['outcome' => $message]);
        $this->log->line($message);

        // The new code booted; the site may serve while the operator decides.
        $this->rollback->disarmBootGuard();
        $this->maintenance->leave();
        CoreUpdateProgress::set(CoreUpdateState::Failed, $message, 100, $to);
        $this->events->dispatch(new CoreUpdateFailed($from, $to, $reason, rolledBack: false));

        return RunOutcome::needsAttention($message);
    }

    private function bumpAttempts(UpdateJournal $journal): void
    {
        $finalize = $journal->get('finalize', []);
        $finalize = is_array($finalize) ? $finalize : [];
        $attempts = $finalize['attempts'] ?? 0;
        $finalize['attempts'] = (is_int($attempts) ? $attempts : 0) + 1;

        $journal->transition(RunState::Finalizing, ['finalize' => $finalize]);
    }

    private function progress(string $message, int $percent, string $to): void
    {
        CoreUpdateProgress::set(CoreUpdateState::Running, $message, $percent, $to);
        $this->log->line("Finalizing {$percent}% — {$message}");
    }

    /** @return list<string> */
    private function strings(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** How far along the finalize a state is, so a resumed run skips what it already did. */
    private static function rank(RunState $state): int
    {
        return match ($state) {
            RunState::BootHealthy => 1,
            RunState::Migrated => 2,
            RunState::PluginsNotified => 3,
            RunState::CachesCleared => 4,
            RunState::Committed => 5,
            RunState::Completed => 6,
            default => 0,
        };
    }
}
