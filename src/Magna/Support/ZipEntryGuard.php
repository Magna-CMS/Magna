<?php

declare(strict_types=1);

namespace Magna\Support;

use ZipArchive;

/**
 * The two entry-level checks every safe zip extraction needs, in one place.
 *
 * RestoreService and PackageExtractor each carried verbatim copies of the
 * traversal check and the symlink check — and RestoreService's own comment
 * records that the copies had already drifted once (the symlink check was
 * "the one check this extractor was missing relative to PackageExtractor").
 * Predicates rather than throwers, so each caller keeps its own exception
 * type and message; what cannot drift any more is the logic.
 */
final class ZipEntryGuard
{
    /** Unix S_IFLNK bit pattern in ZipArchive's packed external attributes (upper 16 bits = st_mode). */
    private const S_IFLNK = 0120000;

    private const S_IFMT = 0170000;

    /**
     * Whether this entry NAME could escape the extraction directory —
     * traversal (`..`), an absolute path (either separator), a Windows
     * drive prefix, or a null byte.
     */
    public static function unsafeName(string $name): bool
    {
        return str_contains($name, '..')
            || str_starts_with($name, '/')
            || str_starts_with($name, '\\')
            || preg_match('/^[a-zA-Z]:/', $name) === 1
            || str_contains($name, "\0");
    }

    /**
     * Whether this entry is a Unix symlink. A symlink's NAME can look
     * perfectly safe while its TARGET points outside the extraction
     * directory — a separate escape vector from traversal in the name.
     *
     * External attributes only encode a Unix mode when the archive was
     * written on a Unix opsys — Windows-authored zips pack something else
     * in those bits entirely, so anything non-Unix reports false.
     */
    public static function isSymlinkEntry(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;

        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        $mode = ($attr >> 16) & 0xFFFF;

        return ($mode & self::S_IFMT) === self::S_IFLNK;
    }
}
