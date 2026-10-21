<?php

declare(strict_types=1);

namespace Magna\Frontend;

use FilesystemIterator;
use Magna\Frontend\Events\PublicContentChanged;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * What every path that puts rendering files on disk owes the site afterwards:
 * a theme or plugin install or update, and a theme switch.
 *
 * Two caches answer for the files that were there before, and neither can see
 * the swap happen:
 *
 *   - Blade's compiled views. A view is recompiled only when its source is
 *     newer than the compiled copy, and an extracted package keeps each
 *     archive entry's mtime — the build machine's edit time, and Composer
 *     does the same. A release built yesterday and installed today is
 *     therefore OLDER than the view compiled from the release it replaced,
 *     and Blade goes on rendering that one: lekha/billing on
 *     lekha.jrstudios.dev (core 1.6.2) kept its old layout until someone ran
 *     `view:clear` by hand. Each view under the replaced directory is stamped
 *     with the time it actually arrived, which is what its mtime should have
 *     said. Only that tree is touched, so every other compiled view stays
 *     warm, and no concurrent request loses a compiled file mid-include the
 *     way it could under `view:clear`.
 *   - Whatever caches public responses (Magna Pages' page cache). That cache
 *     belongs to a plugin, so core says everything changed and the owner
 *     decides what to flush.
 *
 * Best-effort throughout, like PluginCacheInvalidator: by now the files are in
 * place, and a cache that will not clear must not turn a finished install into
 * a reported failure.
 */
final class RenderedOutputRefresher
{
    /** Files under $directory were just written: render from them, not from caches of what was there. */
    public function filesReplaced(string $directory, string $source): void
    {
        $this->markViewsFresh($directory);
        $this->outputChanged($source);
    }

    /** Any public page may render differently now. $source is for logs only. */
    public function outputChanged(string $source): void
    {
        try {
            event(PublicContentChanged::all($source));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function markViewsFresh(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $now = time();

        try {
            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    @touch($file->getPathname(), $now);
                }
            }
        } catch (Throwable) {
            // An unreadable subdirectory: the views reached so far are fresh,
            // and the rest are no worse off than before.
        }
    }
}
