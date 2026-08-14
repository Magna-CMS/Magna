<?php

declare(strict_types=1);

namespace Magna\Blocks\Resolution;

use Throwable;

/**
 * What one page render is allowed to spend on plugin resolvers.
 *
 * A dynamic tag and a data source are plugin code running inside a visitor's
 * page render. A throwing one already degrades to a gap — but a merely *slow*
 * one does not throw, and forty slow ones on a page with a loop over forty
 * rows is a page nobody waits for. This is the ceiling on that: a count of
 * invocations and a wall-clock total, both per render.
 *
 * **What it can and cannot do, stated plainly.** PHP cannot interrupt a call
 * already running without killing the process, so this bounds the render
 * *between* invocations: once the ceiling is passed, every later resolver is
 * refused and its caller renders the same gap it renders for a resolver that
 * threw. A single resolver that hangs for ever still hangs the request — that
 * is what the web server's own timeout is for, and pretending otherwise here
 * would be a comforting lie in a docblock.
 *
 * A refused resolver leaves the page visibly incomplete, so a render that
 * spent its budget must not be cached: {@see exhausted()} is what the page
 * controller asks before storing the HTML. Baking a degraded copy into the
 * shared cache would turn one slow minute into a permanently broken page.
 *
 * Request-scoped, not a singleton: under Octane a singleton would carry one
 * request's spending into the next and start refusing resolvers on a page
 * that had asked for nothing yet.
 */
final class ResolverBudget
{
    private int $invocations = 0;

    private float $elapsedMilliseconds = 0.0;

    private bool $reported = false;

    /**
     * @param  int  $maxInvocations  How many resolvers one render may call.
     * @param  int  $maxMilliseconds  How long they may take in total.
     * @param  int  $slowMilliseconds  A single call above this is logged by
     *                                 name, so the plugin author hears about
     *                                 it before the budget starts refusing.
     */
    public function __construct(
        private readonly int $maxInvocations = 200,
        private readonly int $maxMilliseconds = 750,
        private readonly int $slowMilliseconds = 250,
    ) {}

    /**
     * Run one resolver against the budget, or refuse it.
     *
     * Returns null when the budget is spent — the caller degrades, exactly as
     * it does for a resolver that is not registered. A resolver that throws is
     * still the caller's to catch: this measures cost, it does not decide what
     * a failure means.
     *
     * @template TValue
     *
     * @param  string  $kind  What is being resolved ('tag', 'source') — for the log line.
     * @param  callable(): TValue  $resolve
     * @return TValue|null
     */
    public function spend(string $kind, string $handle, callable $resolve): mixed
    {
        if ($this->exhausted()) {
            $this->reportOnce($kind, $handle);

            return null;
        }

        $startedAt = microtime(true);

        try {
            return $resolve();
        } finally {
            // In `finally` so a throwing resolver is still charged for the
            // time it burnt: a resolver that takes two seconds and then fails
            // has cost the visitor exactly as much as one that succeeded.
            $this->charge($kind, $handle, (microtime(true) - $startedAt) * 1000);
        }
    }

    /** True once this render has spent everything it is allowed to. */
    public function exhausted(): bool
    {
        return $this->invocations >= $this->maxInvocations
            || $this->elapsedMilliseconds >= (float) $this->maxMilliseconds;
    }

    public function invocations(): int
    {
        return $this->invocations;
    }

    public function elapsedMilliseconds(): float
    {
        return $this->elapsedMilliseconds;
    }

    /**
     * Start again.
     *
     * The container hands each request its own instance, so this exists for
     * the callers that render more than one document in a single request —
     * the fragment endpoint and the builder preview — and for tests.
     */
    public function reset(): void
    {
        $this->invocations = 0;
        $this->elapsedMilliseconds = 0.0;
        $this->reported = false;
    }

    private function charge(string $kind, string $handle, float $milliseconds): void
    {
        $this->invocations++;
        $this->elapsedMilliseconds += $milliseconds;

        if ($milliseconds >= (float) $this->slowMilliseconds) {
            $this->log(sprintf(
                'Slow %s [%s] took %dms of this page\'s render budget.',
                $kind,
                $handle,
                (int) round($milliseconds),
            ));
        }
    }

    /**
     * One line per render, not one per refusal.
     *
     * A page that blew its budget usually blows it on every remaining node,
     * and forty identical warnings in a log is how the one that mattered gets
     * missed.
     */
    private function reportOnce(string $kind, string $handle): void
    {
        if ($this->reported) {
            return;
        }

        $this->reported = true;

        $this->log(sprintf(
            'Render budget spent (%d resolvers, %dms); refusing %s [%s] and everything after it.',
            $this->invocations,
            (int) round($this->elapsedMilliseconds),
            $kind,
            $handle,
        ));
    }

    /**
     * Logging must never be the reason a page fails.
     *
     * This runs inside a visitor's render, and a misconfigured log channel
     * throwing here would turn a slow page into a 500 — which is precisely
     * the outcome this class exists to prevent.
     */
    private function log(string $message): void
    {
        try {
            logger()->warning($message);
        } catch (Throwable) {
            // Nothing to do about it, and nothing worth breaking a page over.
        }
    }
}
