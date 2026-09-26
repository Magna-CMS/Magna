<?php

declare(strict_types=1);

namespace Magna\Updater\Preflight;

/**
 * One reason an update should not start, in words the admin can act on.
 *
 * `blocking` is always true today; the field exists so a later check can
 * advise ("this host has no Composer, dependency changes will not apply")
 * without stopping the run.
 */
final readonly class PreflightProblem
{
    public function __construct(
        public string $code,
        public string $message,
        public bool $blocking = true,
    ) {}
}
