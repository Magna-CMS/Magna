<?php

declare(strict_types=1);

namespace Magna\Updater\Engine;

use Closure;
use Magna\Marketplace\ComposerRunner;
use Magna\Updater\CoreWritability;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\ReleasePlan;
use Magna\Updater\Run\RunState;
use Magna\Updater\Run\UpdateJournal;
use Magna\Updater\UpdateMode;
use Magna\Updater\UpdatePaths;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Everything a release engine may do, and nothing else — engine API 1.
 *
 * The engine is the release's own apply logic, run by the updater the site
 * already has. It never touches the filesystem itself: it asks this object,
 * and every path it names goes through PathGuard before a byte moves. That is
 * the whole design in one sentence — the NEW release decides what happens,
 * the INSTALLED release decides what may.
 *
 * The API is numbered, not typed: the engine is written against
 * `object $ctx` and the manifest states which API it needs, so an installed
 * updater refuses an engine it cannot serve instead of failing halfway.
 *
 * Vendor is the one place the engine asks questions before acting. The
 * release says whether it wants vendor/ replaced (its manifest); the site
 * says what that costs (VendorPolicy): nothing if it already holds the
 * archive's packages, a whole-tree swap if nothing in it is its own, a swap
 * followed by `composer require` of its own packages if Composer is there —
 * or a refusal before anything is staged, and the site keeps its release.
 */
final class EngineContext
{
    public const API = 1;

    /**
     * How the site's own packages are put back after the release's vendor/
     * is in: pinned, without scripts (the finalizer rediscovers packages
     * itself), without the advisory audit, with the optimised map. Every
     * option here must be one Composer 2 accepts — a 1.x-only flag made
     * Composer print its usage and the whole switch roll back, on a live
     * site, and tests/Feature/Updater/ComposerRequireArgumentsTest.php now
     * checks the list against the installed Composer.
     *
     * @var list<string>
     */
    public const COMPOSER_REQUIRE_ARGUMENTS = ['require', '--no-scripts', '--no-progress', '--no-audit', '--optimize-autoloader'];

    private bool $switching = false;

    /**
     * @param  Closure(): void  $enterMaintenance
     * @param  Closure(string, int): void  $progress
     * @param  Closure(string): void  $log
     */
    public function __construct(
        private readonly StagedSwap $swap,
        private readonly UpdateJournal $journal,
        private readonly ReleasePlan $plan,
        private readonly string $archiveRoot,
        private readonly UpdatePaths $paths,
        private readonly Filesystem $files,
        private readonly InstalledFootprint $footprint,
        private readonly ComposerRunner $composer,
        private readonly string $fromVersion,
        private readonly string $toVersion,
        private readonly UpdateMode $mode,
        private readonly Closure $enterMaintenance,
        private readonly Closure $progress,
        private readonly Closure $log,
    ) {}

    public function apiVersion(): int
    {
        return self::API;
    }

    public function basePath(): string
    {
        return $this->paths->basePath;
    }

    public function archiveRoot(): string
    {
        return $this->archiveRoot;
    }

    /** @return array<array-key, mixed> the validated manifest, as written */
    public function manifest(): array
    {
        return $this->plan->manifest === null ? [] : $this->plan->manifest->raw;
    }

    public function fromVersion(): string
    {
        return $this->fromVersion;
    }

    public function toVersion(): string
    {
        return $this->toVersion;
    }

    public function mode(): string
    {
        return $this->mode->value;
    }

    public function log(string $line): void
    {
        ($this->log)($line);
    }

    public function progress(string $message, int $percent): void
    {
        ($this->progress)($message, $percent);
    }

    /** Stop here, before anything has changed: the site keeps its release and the admin reads why. */
    public function refuse(string $reason): never
    {
        throw new RuntimeException($reason);
    }

    /**
     * Copy the release's version of a path beside the live one. A path the
     * release declared optional and the archive lacks is skipped with a note;
     * any other missing path is a partial release and is refused.
     */
    public function stage(string $relative): void
    {
        $source = rtrim($this->archiveRoot, '/\\').'/'.$relative;

        if (! is_dir($source) && ! is_file($source)) {
            if (in_array($relative, $this->plan->optionalPaths, true)) {
                $this->log("{$relative} is not in this archive (optional), left as is.");

                return;
            }

            throw new RuntimeException("The archive does not contain {$relative}, which the release says it needs.");
        }

        $this->swap->stage($relative, $this->archiveRoot);
        $this->journal->transition(RunState::Staged);
    }

    /**
     * The point of no cheap return: the site goes into maintenance mode and
     * the renames begin. Called once; swap() calls it if the engine forgot.
     */
    public function beginSwitch(): void
    {
        if ($this->switching) {
            return;
        }

        $this->switching = true;
        ($this->enterMaintenance)();
        $this->journal->transition(RunState::Down);
    }

    /** Put a staged path live. A path that was never staged (optional, absent) is left alone. */
    public function swap(string $relative): void
    {
        $info = $this->journal->paths()[$relative] ?? null;

        if ($info === null || ($info['state'] ?? null) !== 'staged') {
            return;
        }

        $this->beginSwitch();
        $this->swap->swap($relative);
        $this->journal->transition(RunState::Swapped);
    }

    /** Take a path the release retired out of the live tree, keeping it for rollback. */
    public function remove(string $relative): void
    {
        $this->beginSwitch();
        $this->swap->remove($relative);
    }

    /**
     * Declare a directory whose live contents were deliberately not carried
     * over (compiled caches under bootstrap/cache, say): it is made to exist,
     * empty if need be, so the code that writes into it finds it.
     */
    public function preserveDrop(string $relative): void
    {
        $reason = (new PathGuard)->reject($relative);

        if ($reason !== null && ! str_starts_with($relative, 'bootstrap/cache')) {
            throw new RuntimeException($reason);
        }

        $this->files->mkdir($this->paths->base($relative), 0755);

        $dropped = $this->journal->get('dropped', []);
        $dropped = is_array($dropped) ? $dropped : [];
        $dropped[] = $relative;

        $this->journal->set(['dropped' => array_values(array_unique(array_filter($dropped, 'is_string')))]);
    }

    // ── Vendor ────────────────────────────────────────────────────────────

    /** What the release asked for: `keep` or `replace` (ManifestRules::VENDOR_STRATEGIES). */
    public function vendorStrategy(): string
    {
        return $this->plan->manifest === null ? 'keep' : $this->plan->manifest->vendorStrategy;
    }

    /** True when the site already holds every package the release was built with. */
    public function vendorMatches(): bool
    {
        return $this->vendorPolicy()->siteMatchesArchive();
    }

    /**
     * Packages the site asks Composer for that the release does not know,
     * at the versions it has them: what a whole-tree swap would drop, and
     * what `composer require` has to put back afterwards.
     *
     * @return array<string, string> name => version
     */
    public function foreignPackages(): array
    {
        return $this->vendorPolicy()->foreignPackages();
    }

    /**
     * Repositories the site's composer.json names that the release's does
     * not, and that could still serve something. Nothing here knows how to
     * carry one across, so one that a foreign package may need means refusal;
     * one that serves nothing leaves with the site's composer.json.
     *
     * @return list<string>
     */
    public function foreignRepositories(): array
    {
        return $this->vendorPolicy()->foreignRepositories();
    }

    public function composerAvailable(): bool
    {
        return $this->composer->isAvailable();
    }

    /** Written to the journal and the footprint so `magna:core:status` can say what was decided and why. */
    public function recordVendorDecision(string $decision, string $reason): void
    {
        $this->journal->set(['vendor' => ['strategy' => $this->vendorStrategy(), 'decision' => $decision, 'reason' => $reason]]);
        $this->log("Vendor: {$decision} — {$reason}");
    }

    /**
     * Stage the release's vendor/ and Composer manifests beside the live
     * ones. Writability is proven first: vendor/ is the one tree the
     * pre-flight did not walk.
     */
    public function stageVendor(): void
    {
        $writability = new CoreWritability($this->paths->basePath, PathGuard::VENDOR_PATHS);
        $blocker = $writability->summary();

        if ($blocker !== null) {
            throw new RuntimeException($blocker.'. Replacing the site\'s dependencies needs those writable. '.($writability->remedy() ?? ''));
        }

        foreach (PathGuard::VENDOR_PATHS as $relative) {
            $source = rtrim($this->archiveRoot, '/\\').'/'.$relative;

            if (! is_dir($source) && ! is_file($source)) {
                throw new RuntimeException("The archive does not contain {$relative}, which replacing the site's dependencies needs.");
            }

            $this->swap->stage($relative, $this->archiveRoot, vendor: true);
        }

        $this->journal->transition(RunState::Staged);
    }

    /** Put whatever stageVendor() staged live. */
    public function swapVendor(): void
    {
        foreach (PathGuard::VENDOR_PATHS as $relative) {
            $this->swap($relative);
        }
    }

    /**
     * Put the site's own packages back into the release's vendor/, pinned
     * to the versions it had. Runs the site's Composer against the manifests
     * now live; a failure is thrown so the switch is rolled back whole —
     * manifests, vendor/ and all — and the site keeps its release.
     *
     * @param  array<string, string>  $packages  name => version, as foreignPackages() answers
     */
    public function composerRequire(array $packages): void
    {
        if ($packages === []) {
            return;
        }

        if (! $this->composer->isAvailable()) {
            throw new RuntimeException('Composer is not available on this server, so the site\'s own packages cannot be put back after the switch.');
        }

        $arguments = [...self::COMPOSER_REQUIRE_ARGUMENTS];

        foreach ($packages as $name => $version) {
            $arguments[] = $version === '*' ? $name : $name.':'.$version;
        }

        $this->log('Running composer require for the site\'s own packages: '.implode(', ', array_keys($packages)).'…');

        $result = $this->composer->run($arguments, 900);

        if (! $result->successful()) {
            $tail = trim(substr($result->output, -600));

            throw new RuntimeException("composer require failed (exit {$result->exitCode}): {$tail}");
        }

        $this->journal->set(['composer_require' => array_keys($packages)]);
    }

    // ── Hand-off ──────────────────────────────────────────────────────────

    /**
     * Write down what this run delivered, to be promoted to the footprint of
     * record once the new code has proven it boots.
     *
     * @param  array<string, mixed>  $extra
     */
    public function writeFootprintDraft(array $extra = []): void
    {
        // What actually went live — not the plan, which may name optional
        // paths this archive did not carry.
        $delivered = array_keys(array_filter(
            $this->journal->paths(),
            static fn (array $info): bool => ($info['state'] ?? null) === 'swapped',
        ));

        $vendor = $this->journal->get('vendor');

        if (is_array($vendor) && ! isset($extra['vendor'])) {
            $extra['vendor'] = $vendor;
        }

        $this->footprint->draft($this->toVersion, $this->mode->value, $this->journal->runId(), $this->plan->manifest, $delivered, $extra);
    }

    /**
     * The engine's last word: the switch is complete and the new code owns
     * the rest. Must be the final call — the old side returns right after.
     *
     * @param  list<string>  $steps
     */
    public function markFinalizePending(array $steps): void
    {
        $finalize = $this->journal->get('finalize', []);
        $finalize = is_array($finalize) ? $finalize : [];
        $finalize['steps'] = array_values(array_filter($steps, 'is_string'));

        $this->journal->transition(RunState::FinalizePending, ['finalize' => $finalize]);
    }

    /** Undo everything this run put live or took away; the site is back on the previous release. */
    public function rollback(): void
    {
        $this->swap->unswapAll();
    }

    public function isSwitching(): bool
    {
        return $this->switching;
    }

    private function vendorPolicy(): VendorPolicy
    {
        return new VendorPolicy($this->paths->basePath, $this->archiveRoot);
    }
}
