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

    /**
     * Give this process at least $seconds to finish — and never less than it
     * already had.
     *
     * The inline fallbacks (a core update or a plugin install applied in the
     * browser request because no worker answered) need far more than the 30s
     * a web SAPI allows, so they ask for the queued job's timeout instead.
     * Called bare, set_time_limit() grants it, but it also RESETS the limit,
     * so on a process that had none — any CLI run: a queue worker, an artisan
     * command, the test suite — asking for more imposes a cap that was not
     * there. The suite found this the expensive way: one test touching the
     * core-update starter left the whole serial run dying at "Maximum
     * execution time of 1800 seconds exceeded", nowhere near that test and
     * with no php.ini setting to blame.
     *
     * Zero means unlimited, so it is the one value that must survive.
     */
    public static function extendTimeLimit(int $seconds): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        $current = (int) ini_get('max_execution_time');

        if ($current === 0 || $current >= $seconds) {
            return;
        }

        set_time_limit($seconds);
    }
}
