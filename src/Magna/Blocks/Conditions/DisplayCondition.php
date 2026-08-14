<?php

declare(strict_types=1);

namespace Magna\Blocks\Conditions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * A named show/hide rule a plugin exposes to page building — one entry in a
 * node's `settings.conditions`, evaluated on every uncached render
 * (docs/magna-pages/07-EXTENSIBILITY.md).
 *
 * Two built-in types ship with Pages, `auth` and `schedule`, and this is how
 * anything else arrives: a commerce plugin gating a banner on a basket, a
 * membership plugin gating a section on a plan. Before this contract those
 * were impossible — an unrecognised type failed closed, which was right and
 * also a dead end.
 *
 * evaluate() runs on the PUBLIC render path, so it must be cheap and it must
 * not assume a signed-in visitor. It returns a {@see ConditionVerdict}: the
 * answer AND what the answer means for the shared page cache. A condition
 * that reads anything about the individual visitor has to say so — that
 * declaration is the only thing keeping one person's variant out of everyone
 * else's cache.
 *
 * Throwing is not a way to hide something: a condition that fails is treated
 * as one this installation cannot evaluate, which hides the node and makes
 * the page uncacheable. Return `ConditionVerdict::hidden()` when the honest
 * answer is no.
 */
interface DisplayCondition
{
    /** Unique type, `vendor.name` style (`shop.has_items`). */
    public function handle(): string;

    /** Human label, for the condition picker in the builder. */
    public function label(): string;

    /**
     * Decide whether a node carrying this condition should render.
     *
     * @param  array<mixed, mixed>  $condition  The stored rule, including its
     *                                          own settings. Never trust its shape: it was written by a builder that
     *                                          may be older or newer than this code, so read defensively and hide
     *                                          rather than guess.
     * @param  Authenticatable|null  $user  The visitor, or null for a guest.
     * @param  Carbon  $now  The render clock, passed in so a condition is
     *                       testable and so every rule on one page agrees about the time.
     */
    public function evaluate(array $condition, ?Authenticatable $user, Carbon $now): ConditionVerdict;
}
