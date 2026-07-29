<?php

declare(strict_types=1);

namespace Magna\Licensing;

use RuntimeException;
use ZipArchive;

/**
 * Safe extraction for licensed packages, in CORE.
 *
 * The plugin-manager plugin has an equivalent extractor, but it is installed
 * only on the operator's own management system — a customer's CMS does not
 * have it. Licensed installs therefore cannot depend on it, so the same
 * protections live here: every entry name is validated and every symlink
 * entry rejected BEFORE a single byte is written, plus an uncompressed-size
 * ceiling against zip bombs.
 *
 * This is step 3 of the install chain and the weakest of the three on its
 * own: it proves the archive is structurally safe, never that it is
 * authentic. LicenseInstaller runs signature and checksum verification
 * first — do not call this directly on untrusted bytes.
 */
class PackageExtractor
{
    /** Uncompressed-size ceiling — blocks a small-compressed/huge-decompressed zip bomb. */
    private const MAX_UNCOMPRESSED_BYTES = 500 * 1024 * 1024;

    /** Unix S_IFLNK bit pattern in ZipArchive's packed external attributes (upper 16 bits = st_mode). */
    private const S_IFLNK = 0120000;

    private const S_IFMT = 0170000;

    /** @throws RuntimeException on an invalid, unsafe, or unreadable archive */
    public function extract(string $zipPath, string $targetDir): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The downloaded package is not a valid zip archive.');
        }

        try {
            $count = $zip->numFiles;
            $totalUncompressed = 0;

            for ($i = 0; $i < $count; $i++) {
                $name = $zip->getNameIndex($i);

                if ($name === false) {
                    throw new RuntimeException('Could not read an entry in the downloaded package.');
                }

                $this->assertSafeEntryName($name);
                $this->assertNotSymlink($zip, $i, $name);

                $stat = $zip->statIndex($i);
                $totalUncompressed += is_array($stat) ? $stat['size'] : 0;

                if ($totalUncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('The downloaded package is too large once decompressed.');
                }
            }

            if (! is_dir($targetDir) && ! mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
                throw new RuntimeException("Could not create extraction directory: {$targetDir}");
            }

            if (! $zip->extractTo($targetDir)) {
                throw new RuntimeException('Failed to extract the downloaded package.');
            }
        } finally {
            $zip->close();
        }

        // The pre-flight ceiling above trusts the central directory's declared
        // sizes, which are written by whoever built the archive. Measuring what
        // actually landed on disk is the only check the archive cannot lie
        // about — belt and braces, since by this point a bomb has already been
        // written.
        $this->assertExtractedSizeWithinLimit($targetDir);
    }

    private function assertExtractedSizeWithinLimit(string $targetDir): void
    {
        $total = 0;

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($targetDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $total += $file->getSize();
            }

            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('The downloaded package is too large once decompressed.');
            }
        }
    }

    private function assertSafeEntryName(string $name): void
    {
        if (
            str_contains($name, '..')
            || str_starts_with($name, '/')
            || str_starts_with($name, '\\')
            || preg_match('/^[a-zA-Z]:/', $name) === 1
            || str_contains($name, "\0")
        ) {
            throw new RuntimeException("Refusing to extract unsafe path in package: {$name}");
        }
    }

    /**
     * A symlink entry's NAME can look perfectly safe while its TARGET points
     * outside the extraction directory — a separate escape vector from path
     * traversal in the name itself.
     */
    private function assertNotSymlink(ZipArchive $zip, int $index, string $name): void
    {
        $opsys = 0;
        $attr = 0;

        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return;
        }

        // External attributes only encode a Unix mode when the archive was
        // written on a Unix opsys — Windows-authored zips pack something else
        // in these bits entirely.
        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return;
        }

        $mode = ($attr >> 16) & 0xFFFF;

        if (($mode & self::S_IFMT) === self::S_IFLNK) {
            throw new RuntimeException("Refusing to extract symlink entry in package: {$name}");
        }
    }

    /**
     * If the zip wraps everything in one top-level folder (a common export
     * shape), descend into it — magna.json is expected at the extracted
     * root, not one level down.
     */
    public function resolveContentRoot(string $extractPath): string
    {
        $entries = array_values(array_diff(scandir($extractPath) ?: [], ['.', '..']));

        if (count($entries) === 1 && is_dir($extractPath.'/'.$entries[0])) {
            return $extractPath.'/'.$entries[0];
        }

        return $extractPath;
    }
}
