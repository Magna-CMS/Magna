<?php

declare(strict_types=1);

namespace Magna\Marketplace;

use Symfony\Component\Process\Process;

/**
 * Default {@see ComposerRunner} — invokes the real Composer binary via a process
 * in the application root. Locates Composer from COMPOSER_BINARY, the PATH, or a
 * project-local composer.phar, and reports unavailable if none respond.
 */
final class ProcessComposerRunner implements ComposerRunner
{
    /** @var list<string>|null Resolved command prefix, cached after first lookup. */
    private ?array $binary = null;

    private bool $resolved = false;

    /**
     * @param  string  $fallbackHome  Where Composer keeps its cache and config when
     *                                the SAPI provides no home of its own.
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $fallbackHome,
        private readonly ComposerManifestRepair $repair,
    ) {}

    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    public function run(array $args, int $timeout = 300): ComposerResult
    {
        $binary = $this->binary();
        if ($binary === null) {
            return new ComposerResult(127, 'Composer was not found on this server.');
        }

        // Said plainly here rather than left to Composer, which reports an
        // unwritable home as a cache warning followed by an unrelated-looking
        // failure further down.
        $homeProblem = $this->fallbackHomeProblem();
        if ($homeProblem !== null) {
            return new ComposerResult(1, $homeProblem);
        }

        // Before the command, not after a failure: Composer refuses to load a
        // manifest naming a path repository that is not there, so every command
        // — including the ones that would fix it — fails until this runs.
        $repairs = $this->repair->repair();

        $process = new Process(
            [...$binary, ...$args, '--no-interaction'],
            $this->basePath,
            $this->environment(),
            null,
            (float) $timeout,
        );

        try {
            $process->run();
        } catch (\Throwable $e) {
            return new ComposerResult(1, $this->annotate($repairs, $e->getMessage()));
        }

        return new ComposerResult(
            $process->getExitCode() ?? 1,
            $this->annotate($repairs, trim($process->getOutput()."\n".$process->getErrorOutput())),
        );
    }

    /**
     * Repairs go in front of the Composer output so the install log says why a
     * site's manifest changed, rather than changing it silently.
     *
     * @param  list<string>  $repairs
     */
    private function annotate(array $repairs, string $output): string
    {
        if ($repairs === []) {
            return $output;
        }

        return trim(implode("\n", $repairs)."\n\n".$output);
    }

    /**
     * Composer refuses to start with neither HOME nor COMPOSER_HOME set —
     * "The HOME or COMPOSER_HOME environment variable must be set for composer
     * to run correctly". The CLI always has HOME; PHP-FPM and most other web
     * SAPIs do not, so every install triggered from the admin panel died on a
     * server where the same command worked over SSH. Point Composer at a
     * directory the web user can definitely write instead.
     *
     * @return array<string, string|false>
     */
    private function environment(): array
    {
        /** @var array<string, string|false> $env */
        $env = ['COMPOSER_NO_INTERACTION' => '1'] + getenv();

        $hasHome = ($env['COMPOSER_HOME'] ?? '') !== '' || ($env['HOME'] ?? '') !== '';
        if ($hasHome) {
            return $env;
        }

        $this->ensureFallbackHome();

        $env['COMPOSER_HOME'] = $this->fallbackHome;

        return $env;
    }

    /** Composer creates its own home, but only where the web user may write. */
    private function ensureFallbackHome(): void
    {
        if (! is_dir($this->fallbackHome)) {
            @mkdir($this->fallbackHome, 0755, true);
        }
    }

    /**
     * Why Composer cannot run under the fallback home, or null when it can.
     *
     * Only relevant when the fallback is in play at all — a server that sets
     * HOME or COMPOSER_HOME itself owns that directory's permissions.
     */
    private function fallbackHomeProblem(): ?string
    {
        $env = getenv();

        if (($env['COMPOSER_HOME'] ?? '') !== '' || ($env['HOME'] ?? '') !== '') {
            return null;
        }

        $this->ensureFallbackHome();

        if (is_dir($this->fallbackHome) && is_writable($this->fallbackHome)) {
            return null;
        }

        return 'Composer needs a writable home directory and this server provides none. '
            ."Create {$this->fallbackHome} writable by the web server user, or set COMPOSER_HOME for the PHP-FPM pool.";
    }

    /** @return list<string>|null */
    private function binary(): ?array
    {
        if ($this->resolved) {
            return $this->binary;
        }

        $this->resolved = true;

        foreach ($this->candidates() as $candidate) {
            // Same environment as a real run: a probe that succeeds under a
            // different env would report a Composer that then fails to start.
            $check = new Process([...$candidate, '--version'], $this->basePath, $this->environment(), null, 15.0);
            try {
                $check->run();
            } catch (\Throwable) {
                continue;
            }

            if ($check->isSuccessful()) {
                return $this->binary = $candidate;
            }
        }

        return $this->binary = null;
    }

    /** @return list<list<string>> */
    private function candidates(): array
    {
        $candidates = [];

        $env = getenv('COMPOSER_BINARY');
        if (is_string($env) && $env !== '') {
            $candidates[] = str_ends_with($env, '.phar') ? [PHP_BINARY, $env] : [$env];
        }

        $candidates[] = ['composer'];

        $phar = $this->basePath.DIRECTORY_SEPARATOR.'composer.phar';
        if (is_file($phar)) {
            $candidates[] = [PHP_BINARY, $phar];
        }

        return $candidates;
    }
}
