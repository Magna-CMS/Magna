<?php

declare(strict_types=1);

namespace Magna\Plugins\Commands;

use Illuminate\Console\Command;
use Magna\MagnaServiceProvider;
use Magna\Plugins\DependencyResolver;
use Magna\Plugins\Exceptions\DependencyException;
use Magna\Plugins\Manifest;
use Magna\Plugins\PluginDiscovery;

/**
 * Validate discovered plugins for release/CI: manifest compatibility, the
 * dependency graph (missing / unsatisfied / conflicting / circular) and
 * cross-plugin collisions (duplicate permission keys). Exits non-zero on any
 * error so it drops straight into a CI pipeline; `--json` emits a machine
 * report, `--strict` promotes warnings to errors.
 */
class PluginValidateCommand extends Command
{
    protected $signature = 'magna:plugin:validate
        {name? : Validate only this plugin (vendor/package); omit to validate all}
        {--all : Validate every discovered plugin (default when no name is given)}
        {--json : Output a machine-readable JSON report}
        {--strict : Treat warnings as errors (exit non-zero on warnings)}';

    protected $description = 'Validate plugin manifests, dependencies and cross-plugin collisions.';

    public function handle(PluginDiscovery $discovery, DependencyResolver $resolver): int
    {
        /** @var array<string, Manifest> $manifests */
        $manifests = [];
        foreach ($discovery->discover() as $info) {
            $manifests[$info->manifest->name] = $info->manifest;
        }

        $name = $this->argument('name');
        if (is_string($name) && ! isset($manifests[$name])) {
            $this->error("Plugin \"{$name}\" was not discovered.");

            return self::FAILURE;
        }

        /** @var list<string> $targets */
        $targets = is_string($name) ? [$name] : array_keys($manifests);

        /** @var array<string, array{errors: list<string>, warnings: list<string>}> $report */
        $report = [];
        $errorsByPlugin = $this->collectErrors($resolver, $manifests, $targets);
        $warningsByPlugin = $this->collectWarnings($manifests, $targets);

        foreach ($targets as $target) {
            $report[$target] = [
                'errors' => $errorsByPlugin[$target] ?? [],
                'warnings' => $warningsByPlugin[$target] ?? [],
            ];
        }

        return $this->render($report);
    }

    /**
     * @param  array<string, Manifest>  $manifests
     * @param  list<string>  $targets
     * @return array<string, list<string>>
     */
    private function collectErrors(DependencyResolver $resolver, array $manifests, array $targets): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        // Core compatibility (per plugin).
        foreach ($targets as $name) {
            if (! $manifests[$name]->isCompatibleWith(MagnaServiceProvider::VERSION)) {
                $errors[$name][] = "Requires Magna {$manifests[$name]->magnaCompat}, but core is ".MagnaServiceProvider::VERSION.'.';
            }
        }

        // Dependency graph (set-level; anchored to a named/first target).
        try {
            $resolver->resolveBootOrder($manifests);
        } catch (DependencyException $e) {
            $anchor = $targets[0] ?? null;
            if ($anchor !== null) {
                $errors[$anchor][] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, Manifest>  $manifests
     * @param  list<string>  $targets
     * @return array<string, list<string>>
     */
    private function collectWarnings(array $manifests, array $targets): array
    {
        /** @var array<string, list<string>> $owners */
        $owners = [];
        foreach ($manifests as $pluginName => $manifest) {
            foreach ($manifest->permissions as $permission) {
                $owners[$permission][] = $pluginName;
            }
        }

        /** @var array<string, list<string>> $warnings */
        $warnings = [];
        foreach ($targets as $name) {
            foreach ($manifests[$name]->permissions as $permission) {
                $others = array_values(array_diff($owners[$permission] ?? [], [$name]));
                if ($others !== []) {
                    $warnings[$name][] = "Permission \"{$permission}\" is also declared by: ".implode(', ', $others).'.';
                }
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, array{errors: list<string>, warnings: list<string>}>  $report
     */
    private function render(array $report): int
    {
        $strict = (bool) $this->option('strict');

        $totalErrors = 0;
        $totalWarnings = 0;
        foreach ($report as $r) {
            $totalErrors += count($r['errors']);
            $totalWarnings += count($r['warnings']);
        }

        $failed = $totalErrors > 0 || ($strict && $totalWarnings > 0);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => ! $failed,
                'errors' => $totalErrors,
                'warnings' => $totalWarnings,
                'plugins' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        foreach ($report as $name => $r) {
            if ($r['errors'] === [] && $r['warnings'] === []) {
                $this->line("<info>✓</info> {$name}");

                continue;
            }

            $this->line("<comment>{$name}</comment>");
            foreach ($r['errors'] as $error) {
                $this->line("  <fg=red>error</> {$error}");
            }
            foreach ($r['warnings'] as $warning) {
                $this->line("  <fg=yellow>warning</> {$warning}");
            }
        }

        $this->newLine();
        $this->line("Errors: {$totalErrors}   Warnings: {$totalWarnings}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
