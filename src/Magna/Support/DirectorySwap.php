<?php

declare(strict_types=1);

namespace Magna\Support;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * Replace a package directory with a new one without ever leaving the
 * install in a state an interrupted process can break.
 *
 * The previous shape renamed the live directory to a VISIBLE sibling
 * ({name}.replacing-{rand}) before moving the new content in. Two ways
 * that failed in practice: a process killed between the two renames left
 * the plugin gone entirely, and the stray sibling sat exactly where the
 * Composer path-repository glob (plugins-dev, any vendor, any package)
 * matches — a duplicate package definition that broke every composer
 * command until someone moved the directory out by hand. One interrupted
 * update destroyed a working roya/erp install this way.
 *
 * The new content is staged INTO the parent first, dot-prefixed: glob
 * patterns skip dot entries, so debris from an interrupted run is invisible
 * to Composer and discovery, and the live directory changes hands in two
 * same-directory renames with nothing slow between them. The
 * cross-filesystem move (storage tmp into the install root) happens while
 * the live directory is still untouched — a failure there changes nothing.
 *
 * Shared by plugin installs (LicenseInstaller) and theme installs
 * (ThemeInstaller), which until then each carried this sequence.
 */
final class DirectorySwap
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * @throws RuntimeException when the new content could not be put in place;
     *                          the previous directory is restored first
     */
    public function swap(string $contentRoot, string $targetDir): void
    {
        $parent = dirname($targetDir);
        $staging = $parent.'/.incoming-'.Str::random(8);
        $displaced = $parent.'/.replaced-'.Str::random(8);

        $this->files->rename($contentRoot, $staging);

        $this->files->rename($targetDir, $displaced);

        try {
            $this->files->rename($staging, $targetDir);
        } catch (Throwable $e) {
            $this->files->rename($displaced, $targetDir);
            $this->files->remove($staging);

            throw new RuntimeException('The update could not be written to disk; the previous version was restored.', 0, $e);
        }

        $this->files->remove($displaced);
    }
}
