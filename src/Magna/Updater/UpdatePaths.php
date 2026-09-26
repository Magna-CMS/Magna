<?php

declare(strict_types=1);

namespace Magna\Updater;

/**
 * Where a core update reads and writes.
 *
 * Injected rather than read from base_path()/storage_path() at the call site so
 * the updater can be pointed at a fixture install: the overlay, the snapshot and
 * the temp handling could never be executed by a test while they were welded to
 * the running application's own tree — which is why none of them ever were.
 */
final readonly class UpdatePaths
{
    public function __construct(
        public string $basePath,
        public string $storagePath,
    ) {}

    public function base(string $relative = ''): string
    {
        return $relative === '' ? $this->basePath : $this->basePath.'/'.ltrim($relative, '/');
    }

    public function storage(string $relative = ''): string
    {
        return $relative === '' ? $this->storagePath : $this->storagePath.'/'.ltrim($relative, '/');
    }

    /** Everything the updater keeps between and during runs. */
    public function updatesDir(): string
    {
        return $this->storage('app/magna-updates');
    }

    /** Downloaded archives and their extractions; disposable. */
    public function tmpDir(): string
    {
        return $this->updatesDir().'/tmp';
    }

    /** Pre-overlay snapshots, one directory per run. */
    public function backupsDir(): string
    {
        return $this->updatesDir().'/backups';
    }

    /** One directory per run: its log, and later its journal. */
    public function runsDir(): string
    {
        return $this->updatesDir().'/runs';
    }

    /** The footprint a switch writes before the new code has proven itself. */
    public function draftFootprint(): string
    {
        return $this->updatesDir().'/installed.json.pending';
    }
}
