<?php

declare(strict_types=1);

namespace Magna\Updater\Events;

/**
 * An update did not complete. `rolledBack` says which release is live: true
 * means the previous one was put back; false means the new code is on disk
 * and the run needs attention (a migration failed, say) before it is over.
 */
final readonly class CoreUpdateFailed
{
    public function __construct(
        public string $from,
        public string $to,
        public string $reason,
        public bool $rolledBack,
    ) {}
}
