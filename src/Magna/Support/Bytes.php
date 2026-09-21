<?php

declare(strict_types=1);

namespace Magna\Support;

/**
 * The one byte-count formatter. Four private copies of the same GB/MB/KB
 * ladder (BackupResource, MediaStatsWidget, RunBackupJob, the backup
 * notification) plus two ad-hoc inline variants had grown across the admin —
 * with the inline ones already disagreeing on precision. Callers that need
 * a null placeholder ('—', 'unknown') handle null themselves; the ladder
 * lives here.
 */
final class Bytes
{
    public static function human(int|float $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => number_format($bytes / 1_073_741_824, 2).' GB',
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 1).' MB',
            $bytes >= 1_024 => number_format($bytes / 1_024, 0).' KB',
            default => number_format($bytes).' B',
        };
    }
}
