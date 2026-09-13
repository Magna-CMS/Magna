<?php

declare(strict_types=1);

namespace Magna\Auth\Concerns;

/**
 * The one way an HTTP surface names "who did this" for the audit trail.
 *
 * User keys are ULIDs (strings), but older rows and some auth drivers hand
 * back integers — the coercion below existed in ManagementController and was
 * re-implemented inline, twice, in the personal-token controller. One trait,
 * zero drift.
 */
trait ResolvesActorId
{
    /** The authenticated actor's id as a string, or null if unavailable. */
    protected function actorId(): ?string
    {
        $id = auth()->id();

        if (is_string($id)) {
            return $id;
        }

        if (is_int($id)) {
            return (string) $id;
        }

        return null;
    }
}
