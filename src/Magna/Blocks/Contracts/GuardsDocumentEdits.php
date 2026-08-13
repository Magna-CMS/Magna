<?php

declare(strict_types=1);

namespace Magna\Blocks\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Optional document-edit locking, provided by whichever plugin owns
 * concurrent editing (magna/pages binds its builder lock here).
 *
 * Core's structured BlockEditor consults this binding when it exists, so
 * the Livewire editor and the visual builder honor ONE lock instead of
 * silently overwriting each other — the cross-editor guarantee the plan
 * calls the round-trip-safe subset contract (§E2). Headless installs never
 * bind it and lose nothing: with a single editor surface there is nothing
 * to contend with.
 */
interface GuardsDocumentEdits
{
    /**
     * Try to hold the edit lock for this entry.
     *
     * @return array{mine: bool, holderName: string|null}
     */
    public function acquire(string $entryId, ?Authenticatable $user): array;

    /** Whether this user currently holds a live lock on the entry. */
    public function holds(string $entryId, ?Authenticatable $user): bool;
}
