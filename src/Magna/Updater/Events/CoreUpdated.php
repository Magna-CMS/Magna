<?php

declare(strict_types=1);

namespace Magna\Updater\Events;

/**
 * The core has been updated and the new release is live, migrated and
 * finished. Dispatched under the NEW code, once per update, after every
 * enabled plugin's `upgrade()` hook has run — so a listener sees the same
 * site those hooks did.
 *
 * Both versions are clean x.y.z strings. A repair (same version re-applied)
 * dispatches this too, with `from` equal to `to`.
 */
final readonly class CoreUpdated
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}
}
