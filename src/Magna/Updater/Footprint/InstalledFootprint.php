<?php

declare(strict_types=1);

namespace Magna\Updater\Footprint;

use Magna\Support\AtomicFile;
use Magna\Updater\Manifest\ReleaseManifest;
use Magna\Updater\UpdatePaths;

/**
 * What was actually delivered to this install, and by what.
 *
 * `MagnaServiceProvider::VERSION` says which code is running, and the code
 * always arrives, so it is the one thing an update cannot get wrong. What it
 * cannot say is whether everything ELSE the release expected arrived with
 * it. A site that updated 1.4.2 to 1.4.3 reported "1.4.3, up to date" while
 * missing the directory that release existed to deliver, because nothing
 * anywhere recorded what the overlay had done. This file does: version,
 * how it got here, which paths were laid down, and which release manifest
 * (by hash) described them. The migrations table for the file layer.
 *
 * Absent on a site that has only ever been updated by an older updater —
 * which is itself the signal the footprint check reads.
 */
final class InstalledFootprint
{
    public const FILENAME = 'installed.json';

    public const SCHEMA = 1;

    public function __construct(private readonly UpdatePaths $paths) {}

    public function path(): string
    {
        return $this->paths->updatesDir().'/'.self::FILENAME;
    }

    /** @return array<array-key, mixed>|null */
    public function read(): ?array
    {
        if (! is_file($this->path())) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($this->path()), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Where a switch writes its record before the new code has proven itself. */
    public function draftPath(): string
    {
        return $this->path().'.pending';
    }

    /**
     * Records a delivery. Written beside then renamed, so a reader never sees
     * half a file. Never throws: the update it describes has already
     * happened, and a footprint that could not be written is a warning the
     * next boot can raise, not a failed update.
     *
     * @param  list<string>  $paths  what was laid down
     * @param  array<string, mixed>  $extra
     */
    public function record(string $version, string $via, ?string $runId, ?ReleaseManifest $manifest, array $paths, array $extra = []): bool
    {
        return $this->writeAtomically($this->path(), $this->describe($version, $via, $runId, $manifest, $paths, $extra));
    }

    /**
     * The same record, written aside: a switch has happened but the new code
     * has not yet booted. promote() makes it the record once it has;
     * discardDraft() drops it when the switch is undone.
     *
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $extra
     */
    public function draft(string $version, string $via, ?string $runId, ?ReleaseManifest $manifest, array $paths, array $extra = []): bool
    {
        return $this->writeAtomically($this->draftPath(), $this->describe($version, $via, $runId, $manifest, $paths, $extra));
    }

    public function promote(): bool
    {
        if (! is_file($this->draftPath())) {
            return false;
        }

        return @rename($this->draftPath(), $this->path());
    }

    public function discardDraft(): void
    {
        @unlink($this->draftPath());
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function describe(string $version, string $via, ?string $runId, ?ReleaseManifest $manifest, array $paths, array $extra): array
    {
        $previous = $this->read();

        return array_replace($extra, [
            'schema' => self::SCHEMA,
            'version' => ltrim($version, 'vV'),
            'installed_via' => $via,
            'run_id' => $runId,
            'applied_at' => date('c'),
            'manifest_sha256' => $manifest?->sha256,
            'manifest_schema' => $manifest?->schema,
            'paths' => $paths,
            'optional_paths' => $manifest === null ? [] : $manifest->optionalPaths,
            'removed_paths' => $manifest === null ? [] : $manifest->removedPaths,
            'removed_classes' => $manifest === null ? [] : $manifest->removedClasses,
            'previous' => $previous === null ? null : [
                'version' => $previous['version'] ?? null,
                'run_id' => $previous['run_id'] ?? null,
                'applied_at' => $previous['applied_at'] ?? null,
            ],
            'layout' => 'in-place',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function writeAtomically(string $path, array $data): bool
    {
        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($payload) && AtomicFile::write($path, $payload);
    }
}
