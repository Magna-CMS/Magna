<?php

declare(strict_types=1);

namespace Magna\Updater;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Can PHP actually overwrite the files a core update replaces?
 *
 * A one-click update overlays CoreUpdater::CORE_OWNED_PATHS. On a host where the
 * archive was extracted by one user and PHP-FPM runs as another, those files are
 * readable but not writable — and the failure surfaced halfway through the
 * overlay, with the rollback failing for the same reason and leaving a core tree
 * mixed between two versions. So writability is established before anything is
 * touched, and the installer reports it up front so it is known at deploy time
 * rather than discovered during an update.
 *
 * Directory writability alone is not enough: a writable directory can hold
 * read-only files, which is exactly the case this exists for.
 */
final class CoreWritability
{
    public function __construct(private readonly string $basePath) {}

    /**
     * The first unwritable entry under each core-owned path, keyed by that path.
     * Empty when a core update could be applied.
     *
     * @return array<string, string>
     */
    public function blockers(): array
    {
        $blockers = [];

        foreach (CoreUpdater::coreOwnedPaths() as $relative) {
            $offender = $this->firstUnwritable($this->basePath.'/'.$relative);

            if ($offender !== null) {
                $blockers[$relative] = $offender;
            }
        }

        // Where the pre-update backup is written. Its absence is fine — it is
        // created on demand — but its parent has to be writable for that.
        $backupParent = $this->basePath.'/storage/app';
        if (is_dir($backupParent) && ! is_writable($backupParent)) {
            $blockers['storage/app'] = 'storage/app';
        }

        return $blockers;
    }

    public function canApplyUpdate(): bool
    {
        return $this->blockers() === [];
    }

    /**
     * One human-readable line naming a path that has to be fixed, or null when
     * everything is writable.
     */
    public function summary(): ?string
    {
        $blockers = $this->blockers();

        if ($blockers === []) {
            return null;
        }

        $first = (string) array_key_first($blockers);
        $offender = $this->relative($blockers[$first]);
        $others = count($blockers) - 1;

        return $others > 0
            ? "PHP cannot write to {$offender} (and {$others} other core path(s))"
            : "PHP cannot write to {$offender}";
    }

    /**
     * The concrete fix, naming both users involved, or null when nothing is
     * blocked.
     *
     * Nearly every report of this traces to the archive being extracted by a
     * different account than PHP runs as — a control panel's file manager acting
     * as root is the usual one. Knowing that only helps if both names are in
     * front of the admin, so they are resolved here instead of being described
     * in prose the admin then has to translate into a command.
     */
    public function remedy(): ?string
    {
        $blockers = $this->blockers();

        if ($blockers === []) {
            return null;
        }

        $phpUser = $this->phpUser();
        $offender = (string) reset($blockers);
        $fileOwner = $this->ownerOf($offender);

        if ($phpUser === null) {
            return 'Make the install directory writable by the user PHP runs as, then reload this page.';
        }

        if ($fileOwner !== null && $fileOwner !== $phpUser) {
            return "These files are owned by \"{$fileOwner}\" but PHP runs as \"{$phpUser}\", so PHP can read them and not write them. "
                ."Run: chown -R {$phpUser}: ".$this->basePath;
        }

        return "PHP runs as \"{$phpUser}\" and owns these files, so the mode is what blocks it. "
            .'Run: find '.$this->basePath.' -type d -exec chmod 755 {} \; and find '.$this->basePath.' -type f -exec chmod 644 {} \;';
    }

    /** Account this PHP process runs as, or null when it cannot be determined (e.g. Windows). */
    public function phpUser(): ?string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if (is_array($info) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        $current = get_current_user();

        return $current !== '' ? $current : null;
    }

    /** Owning account of a path, or null when the platform cannot report it. */
    private function ownerOf(string $path): ?string
    {
        $uid = @fileowner($path);

        if ($uid === false) {
            return null;
        }

        if (function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($uid);

            if (is_array($info) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        return null;
    }

    /**
     * Absolute path of the first entry PHP cannot write under $absolute, or null
     * when every entry is writable. A path that does not exist is not a blocker:
     * the overlay creates it.
     *
     * Every file is examined, not a sample. Sampling was tried first and missed a
     * single read-only file past the cut-off — worthless, because one such file
     * is enough to break an overlay halfway through. The core-owned tree is ~500
     * files, walked once per installer screen or update.
     */
    private function firstUnwritable(string $absolute): ?string
    {
        if (! file_exists($absolute)) {
            return null;
        }

        if (is_file($absolute)) {
            return is_writable($absolute) ? null : $absolute;
        }

        if (! is_writable($absolute)) {
            return $absolute;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            clearstatcache(true, $entry->getPathname());

            if (! $entry->isWritable()) {
                return $entry->getPathname();
            }
        }

        return null;
    }

    /** Trim the install path off, so messages read as "src/Magna/..." not a server path. */
    private function relative(string $absolute): string
    {
        $prefix = $this->basePath.DIRECTORY_SEPARATOR;
        $normalised = str_replace('\\', '/', $absolute);
        $normalisedPrefix = str_replace('\\', '/', $prefix);

        return str_starts_with($normalised, $normalisedPrefix)
            ? substr($normalised, strlen($normalisedPrefix))
            : $normalised;
    }
}
