<?php

declare(strict_types=1);

namespace Magna\Support;

/**
 * Facts about the process Magna is running in.
 *
 * The Octane check reads the LARAVEL_OCTANE environment variable Octane's
 * own start commands set on the worker process. getenv(), not config():
 * the question is about THIS process, and config can be cached from a
 * different one. This class is the single place that reads it — the same
 * filter_var(getenv(...)) line used to be pasted across five files, and an
 * architecture rule now keeps raw getenv() out of everything else.
 */
final class Runtime
{
    public static function isOctane(): bool
    {
        return filter_var(getenv('LARAVEL_OCTANE'), FILTER_VALIDATE_BOOLEAN);
    }
}
