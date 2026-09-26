<?php

declare(strict_types=1);

namespace Magna\Updater\Engine;

use Magna\Updater\Run\UpdateJournal;
use Magna\Updater\UpdatePaths;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * Replaces a live path with the release's copy in two renames.
 *
 * The old overlay mirrored file by file into the live tree — thousands of
 * writes, and a process killed midway (a worker on its timeout, an FPM
 * request past its limit) left a tree that was neither version, with nothing
 * that could describe it. Here the release's copy is first STAGED as a
 * dot-prefixed sibling of the live path, on the same filesystem, while the
 * site keeps serving; the switch is then `rename(live, displaced)` and
 * `rename(staged, live)`, which is as atomic as a filesystem gets and takes
 * milliseconds. The displaced directory IS the rollback point: undoing is the
 * same two renames backwards. Every step is written to the journal before
 * and after, so whatever process finds a half-done run knows which paths are
 * which. Same shape as Magna\Licensing\LicenseInstaller::swapInPlace, which
 * has done this for plugin updates since an interrupted mirror destroyed one.
 *
 * Dot-prefixed siblings because glob patterns skip dot entries: Composer's
 * path-repository discovery and Magna's own plugin discovery never see the
 * debris of an interrupted run.
 */
final class StagedSwap
{
    private const RENAME_ATTEMPTS = 5;

    private const RENAME_RETRY_MICROSECONDS = 250_000;

    public function __construct(
        private readonly Filesystem $files,
        private readonly UpdatePaths $paths,
        private readonly PathGuard $guard,
        private readonly UpdateJournal $journal,
    ) {}

    public static function stagingPath(string $live, string $runId): string
    {
        return dirname($live).'/.'.basename($live).'.incoming-'.$runId;
    }

    public static function displacedPath(string $live, string $runId): string
    {
        return dirname($live).'/.'.basename($live).'.replaced-'.$runId;
    }

    public static function failedPath(string $live, string $runId): string
    {
        return dirname($live).'/.'.basename($live).'.failed-'.$runId;
    }

    /**
     * Copy the release's version of $relative next to the live one. Slow,
     * and safe to be slow: nothing the site serves changes.
     *
     * @param  bool  $vendor  true only from the engine's vendor step, which alone may name vendor/ and the Composer manifests
     *
     * @throws RuntimeException when the guard refuses the path or the archive lacks it
     */
    public function stage(string $relative, string $sourceRoot, bool $vendor = false): void
    {
        $this->assertOwnable($relative, $vendor);

        $source = rtrim($sourceRoot, '/\\').'/'.$relative;
        $live = $this->paths->base($relative);
        $staging = self::stagingPath($live, $this->journal->runId());

        if (! is_dir($source) && ! is_file($source)) {
            throw new RuntimeException("The archive does not contain {$relative}.");
        }

        $this->files->mkdir(dirname($live), 0755);
        $this->files->remove($staging);

        if (is_dir($source)) {
            $this->files->mirror($source, $staging, null, ['override' => true, 'delete' => true]);
        } else {
            $this->files->copy($source, $staging, true);
        }

        $this->journal->recordPath($relative, ['staged' => $staging, 'state' => 'staged']);
    }

    /**
     * Put the staged copy live. Two renames; the second failing undoes the
     * first, so the live path is never absent for longer than a rename.
     */
    public function swap(string $relative): void
    {
        $info = $this->journal->paths()[$relative] ?? null;
        $staging = is_string($info['staged'] ?? null) ? $info['staged'] : null;

        if ($staging === null || (! is_dir($staging) && ! is_file($staging))) {
            throw new RuntimeException("{$relative} was never staged, so it cannot be swapped in.");
        }

        $live = $this->paths->base($relative);
        $displaced = null;

        if (is_dir($live) || is_file($live)) {
            $displaced = self::displacedPath($live, $this->journal->runId());
            $this->files->remove($displaced);
            $this->rename($live, $displaced);
            $this->journal->recordPath($relative, ['displaced' => $displaced, 'state' => 'displacing']);
        }

        try {
            $this->rename($staging, $live);
        } catch (Throwable $e) {
            if ($displaced !== null) {
                $this->rename($displaced, $live);
            }

            $this->journal->recordPath($relative, ['displaced' => null, 'state' => 'staged']);

            throw new RuntimeException("Could not put {$relative} live: ".$e->getMessage(), 0, $e);
        }

        $this->journal->recordPath($relative, ['displaced' => $displaced, 'state' => 'swapped']);
    }

    /** Take a path the release retired out of the tree, keeping it for rollback. */
    public function remove(string $relative): void
    {
        $this->assertOwnable($relative);

        $live = $this->paths->base($relative);

        if (! is_dir($live) && ! is_file($live)) {
            $this->journal->recordPath($relative, ['state' => 'absent']);

            return;
        }

        $displaced = self::displacedPath($live, $this->journal->runId());
        $this->files->remove($displaced);
        $this->rename($live, $displaced);

        $this->journal->recordPath($relative, ['displaced' => $displaced, 'state' => 'removed']);
    }

    /** Undo one path: the release's copy goes aside, the previous content comes back. */
    public function unswap(string $relative): void
    {
        $info = $this->journal->paths()[$relative] ?? [];
        $state = is_string($info['state'] ?? null) ? $info['state'] : '';
        $live = $this->paths->base($relative);
        $displaced = is_string($info['displaced'] ?? null) ? $info['displaced'] : null;

        if ($state === 'staged') {
            $this->discardStaged($relative);

            return;
        }

        if (! in_array($state, ['swapped', 'removed', 'displacing'], true)) {
            return;
        }

        if ($state !== 'removed' && (is_dir($live) || is_file($live))) {
            $failed = self::failedPath($live, $this->journal->runId());
            $this->files->remove($failed);
            $this->rename($live, $failed);
        }

        if ($displaced !== null && (is_dir($displaced) || is_file($displaced))) {
            $this->rename($displaced, $live);
        }

        $this->journal->recordPath($relative, ['state' => 'unswapped']);
    }

    /** Undo every path this run touched, newest first. */
    public function unswapAll(): void
    {
        foreach (array_reverse(array_keys($this->journal->paths())) as $relative) {
            $this->unswap($relative);
        }
    }

    public function discardStaged(string $relative): void
    {
        $info = $this->journal->paths()[$relative] ?? [];
        $staging = is_string($info['staged'] ?? null) ? $info['staged'] : null;

        if ($staging !== null) {
            $this->files->remove($staging);
        }

        $this->journal->recordPath($relative, ['staged' => null, 'state' => 'discarded']);
    }

    /** The switch has proven itself: the previous content and any debris can go. */
    public function commit(): void
    {
        foreach ($this->journal->paths() as $relative => $info) {
            $live = $this->paths->base($relative);

            foreach (['displaced', 'staged'] as $field) {
                $path = is_string($info[$field] ?? null) ? $info[$field] : null;

                if ($path !== null) {
                    $this->files->remove($path);
                }
            }

            $this->files->remove(self::failedPath($live, $this->journal->runId()));

            $this->journal->recordPath($relative, ['displaced' => null, 'staged' => null, 'state' => 'committed']);
        }
    }

    private function assertOwnable(string $relative, bool $vendor = false): void
    {
        $reason = $this->guard->reject($relative, $vendor);

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
    }

    /**
     * A rename that tolerates Windows: an indexer or an antivirus can hold a
     * directory for a moment, and the first attempt fails with "access
     * denied" where a second, a quarter-second later, succeeds.
     */
    private function rename(string $from, string $to): void
    {
        $reason = 'unknown error';

        for ($attempt = 1; $attempt <= self::RENAME_ATTEMPTS; $attempt++) {
            try {
                $this->files->rename($from, $to, true);

                return;
            } catch (Throwable $e) {
                $reason = $e->getMessage();

                if ($attempt < self::RENAME_ATTEMPTS) {
                    usleep(self::RENAME_RETRY_MICROSECONDS);
                }
            }
        }

        throw new RuntimeException("Could not rename {$from} to {$to}: {$reason}");
    }
}
