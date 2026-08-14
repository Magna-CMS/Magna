<?php

declare(strict_types=1);

namespace Magna\Blocks\Conditions;

use Illuminate\Support\Carbon;

/**
 * What one display condition decided, and what that means for the cache.
 *
 * Two answers, not one, because a shared page cache that ignores conditions
 * serves the wrong variant to somebody and never notices. Every condition
 * states its truth AND how long that truth keeps — a rule that says "show
 * this until Friday" is cacheable, but only until Friday.
 *
 * `expiresAt` is the moment this verdict stops being true, where the
 * condition can say. Null means either "never changes on its own" or "not
 * cacheable at all"; the two are told apart by `cacheable`, and a caller
 * folding several verdicts together keeps the EARLIEST expiry, since the
 * first one to turn makes the whole page wrong.
 */
final class ConditionVerdict
{
    public function __construct(
        public readonly bool $visible,
        public readonly bool $cacheable = true,
        public readonly ?Carbon $expiresAt = null,
    ) {}

    /** Shown, and safe in the shared cache indefinitely. */
    public static function visible(): self
    {
        return new self(visible: true);
    }

    /** Hidden, and safe in the shared cache indefinitely. */
    public static function hidden(): self
    {
        return new self(visible: false);
    }

    /**
     * Shown or hidden, but only until the given moment.
     *
     * For a rule whose answer turns on a clock: the cached copy is correct
     * right up to the boundary and wrong one second after it.
     */
    public static function until(bool $visible, ?Carbon $expiresAt): self
    {
        return new self($visible, cacheable: true, expiresAt: $expiresAt);
    }

    /**
     * Hidden, and the page holding it must not be cached.
     *
     * The answer for anything this installation cannot evaluate. It fails
     * closed twice over on purpose: content gated by a rule we do not
     * understand must not leak, and a rule whose cache behaviour we cannot
     * promise must not be promised.
     */
    public static function hiddenUncacheable(): self
    {
        return new self(visible: false, cacheable: false);
    }

    /**
     * Varies per visitor: correct to show, wrong to share.
     *
     * For a condition that reads something about the person asking beyond
     * whether they are signed in — a plan, a role, a basket.
     */
    public static function perVisitor(bool $visible): self
    {
        return new self($visible, cacheable: false);
    }
}
