<?php

declare(strict_types=1);

namespace Magna\Updater;

use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * Keeps storage/app/magna-updates from growing without bound.
 *
 * Every update used to leave a full copy of src/Magna, app, bootstrap and
 * the compiled assets in backups/ forever, and every failed one left its
 * archive and extraction in tmp/ — tens of megabytes a time, on hosts where a
 * full disk is a site that stops writing sessions. Nothing here throws: a
 * backup that cannot be removed is a warning for the run log, not a reason to
 * abort an update.
 */
final class UpdateHousekeeping
{
    /** Snapshots kept after a successful update: the one just taken and the one before it. */
    public const KEEP_BACKUPS = 2;

    /** Age past which a leftover download or extraction is nobody's any more. */
    public const TEMP_MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly Filesystem $files,
        private readonly UpdatePaths $paths,
    ) {}

    /**
     * Removes all but the newest $keep snapshot directories. Names are
     * timestamps (CoreSnapshot::create), so their sort order is their age.
     *
     * @return list<string> the directories removed
     */
    public function pruneBackups(int $keep = self::KEEP_BACKUPS): array
    {
        $directory = $this->paths->backupsDir();

        if (! is_dir($directory)) {
            return [];
        }

        $names = array_values(array_filter(
            scandir($directory) ?: [],
            static fn (string $name): bool => $name !== '.' && $name !== '..' && is_dir($directory.'/'.$name),
        ));

        rsort($names);

        $removed = [];

        foreach (array_slice($names, max(0, $keep)) as $name) {
            if ($this->remove($directory.'/'.$name)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }

    /**
     * Removes downloads and extractions older than $maxAgeSeconds. A run in
     * progress is younger than that by construction; an abandoned one is not.
     *
     * @return list<string> the entries removed
     */
    public function pruneTemp(int $maxAgeSeconds = self::TEMP_MAX_AGE_SECONDS): array
    {
        $directory = $this->paths->tmpDir();

        if (! is_dir($directory)) {
            return [];
        }

        $cutoff = time() - $maxAgeSeconds;
        $removed = [];

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory.'/'.$name;
            $modified = @filemtime($path);

            if ($modified === false || $modified > $cutoff) {
                continue;
            }

            if ($this->remove($path)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }

    /** Run directories kept after a success, newest first; the rest go with their logs. */
    public const KEEP_RUNS = 5;

    /** Journal states in which a run is over and nothing of it is still in flight. */
    private const FINISHED_STATES = ['completed', 'failed', 'rolled_back', 'needs_attention', 'rolled_back_by_boot_guard'];

    /**
     * Removes the release copies a rolled-back run set aside as
     * `.<name>.failed-<run>` siblings. They are kept when the rollback
     * happens so the run can be looked at; by the next run they have been
     * looked at, and a failed vendor/ alone is a hundred megabytes. Only runs
     * whose journal is over are considered, so nothing mid-switch is touched.
     *
     * @return list<string> the copies removed
     */
    public function pruneFailedCopies(): array
    {
        $directory = $this->paths->runsDir();

        if (! is_dir($directory)) {
            return [];
        }

        $removed = [];

        foreach (scandir($directory) ?: [] as $runId) {
            if ($runId === '.' || $runId === '..') {
                continue;
            }

            $journal = json_decode((string) @file_get_contents($directory.'/'.$runId.'/journal.json'), true);

            if (! is_array($journal) || ! in_array($journal['state'] ?? null, self::FINISHED_STATES, true)) {
                continue;
            }

            foreach (array_keys(is_array($journal['paths'] ?? null) ? $journal['paths'] : []) as $relative) {
                $failed = Engine\StagedSwap::failedPath($this->paths->base((string) $relative), $runId);

                if ((is_dir($failed) || is_file($failed)) && $this->remove($failed)) {
                    $removed[] = $failed;
                }
            }
        }

        return $removed;
    }

    /**
     * Removes all but the newest $keep run directories whose journal is over.
     * A run still in progress is never touched, whatever its age.
     *
     * @return list<string> the runs removed
     */
    public function pruneRuns(int $keep = self::KEEP_RUNS): array
    {
        $directory = $this->paths->runsDir();

        if (! is_dir($directory)) {
            return [];
        }

        $names = array_values(array_filter(
            scandir($directory) ?: [],
            static fn (string $name): bool => $name !== '.' && $name !== '..' && is_dir($directory.'/'.$name),
        ));

        rsort($names);

        $removed = [];

        foreach (array_slice($names, max(0, $keep)) as $name) {
            $journal = json_decode((string) @file_get_contents($directory.'/'.$name.'/journal.json'), true);
            $state = is_array($journal) && is_string($journal['state'] ?? null) ? $journal['state'] : 'completed';

            if (! in_array($state, self::FINISHED_STATES, true)) {
                continue;
            }

            if ($this->remove($directory.'/'.$name)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }

    private function remove(string $path): bool
    {
        try {
            $this->files->remove($path);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
