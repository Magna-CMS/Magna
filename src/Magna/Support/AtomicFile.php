<?php

declare(strict_types=1);

namespace Magna\Support;

/**
 * Write a file so that no reader ever sees half of it.
 *
 * The update journal, the delivery footprint and the stale-classmap cache
 * are all read by whichever process comes next — a poll, a worker, the
 * scheduler — while another may still be writing them. Each is written to a
 * sibling and renamed into place; rename is atomic on every filesystem the
 * updater runs on, so a reader gets the old file or the new one, never a
 * truncated one.
 */
final class AtomicFile
{
    /** True when the file now holds $contents; false when it could not be written, with nothing half-done. */
    public static function write(string $path, string $contents): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $contents) === false) {
            return false;
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }
}
