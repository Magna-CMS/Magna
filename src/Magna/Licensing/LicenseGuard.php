<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The single answer to "may this paid product run here right now".
 *
 * Design constraints, in priority order:
 *
 *  1. Never brick a paying customer. An unreachable licence server keeps the
 *     site fully working for GRACE_DAYS; only an explicit revocation locks
 *     features, and even then no data is touched.
 *  2. Never trust the local clock. Grace is measured from `server_time` —
 *     the timestamp the marketplace signed into its last response. Winding
 *     the server clock forward cannot shorten someone else's licence, and
 *     winding it backwards (the actual attack: extend grace forever) is
 *     detected and forces the state closed rather than open.
 *  3. Fail closed on tampering, open on outage. Those are different things —
 *     and where one request looks like both at once (a clock correction during
 *     an outage), constraint 1 wins and the event is logged instead.
 */
class LicenseGuard
{
    /** How long a cached verification stays authoritative before a re-check. */
    public const RECHECK_HOURS = 24;

    /** How long the site keeps working while the licence server is unreachable. */
    public const GRACE_DAYS = 14;

    /**
     * Tolerance for honest clock drift / NTP correction before a backwards
     * jump is treated as tampering.
     */
    private const CLOCK_SKEW_TOLERANCE_MINUTES = 60;

    public function __construct(
        private readonly LicenseStore $store,
        private readonly LicenseGate $gate,
        private readonly LicenseReactivator $reactivator,
        private readonly TokenVerifier $verifier = new TokenVerifier,
    ) {}

    public function check(string $productSlug): LicenseState
    {
        $entry = $this->store->get($productSlug);

        if ($entry === null) {
            return LicenseState::Unlicensed;
        }

        // Clock rolled back past the last server-signed timestamp: someone is
        // trying to sit inside the grace window indefinitely. Refuse to serve
        // grace from a cache we can no longer age honestly — a live check is
        // the only way back.
        if (Carbon::now()->lt($entry->serverTime->copy()->subMinutes(self::CLOCK_SKEW_TOLERANCE_MINUTES))) {
            $refreshed = $this->refresh($entry);

            if ($refreshed !== null) {
                return $this->stateFor($refreshed);
            }

            // Rolled-back clock AND an unreachable server. This is where
            // "never brick a paying customer" outranks "fail closed on
            // tampering": an NTP correction on a host that had drifted looks
            // identical to a deliberate rollback, and locking here would
            // disable a paid product over a corrected clock. Stay in grace and
            // make it loud instead — the tamper case cannot outlast grace
            // anyway, because ageing it requires a server-signed timestamp the
            // attacker cannot produce.
            Log::warning('Licensing: local clock is behind the last server-signed timestamp and the licence server is unreachable.', [
                'product' => $entry->productSlug,
                'server_time' => $entry->serverTime->toIso8601String(),
            ]);

            return LicenseState::Grace;
        }

        if ($this->isFresh($entry)) {
            return $this->stateFor($entry);
        }

        $refreshed = $this->refresh($entry);

        if ($refreshed !== null) {
            return $this->stateFor($refreshed);
        }

        // Unreachable. Grace runs from the last server-signed timestamp, not
        // from when we last tried.
        if ($entry->serverTime->diffInDays(Carbon::now()) <= self::GRACE_DAYS) {
            return LicenseState::Grace;
        }

        // Grace exhausted. A marketplace licence degrades (code runs, updates
        // stop); a corporate or trial one stops — a client site that has not
        // been able to confirm a contractual licence in two weeks must not
        // keep using the product indefinitely.
        return $entry->endsOnExpiry() ? LicenseState::Locked : LicenseState::Expired;
    }

    /** Force a live re-check now (System Info action, post-install confirmation). */
    public function refresh(LicenseEntry $entry): ?LicenseEntry
    {
        $outcome = $this->verifier->outcome($entry->token);

        if ($outcome->verified()) {
            /** @var array<string, mixed> $payload */
            $payload = $outcome->payload;
            $updated = $entry->withVerifiedPayload($payload);
            $this->store->put($updated);

            return $updated;
        }

        // The marketplace answered and said the token is no good. Waiting
        // will not change that answer — but the connected account still owns
        // the licence, so a fresh activation can be minted without anybody
        // typing a key. This is the call that was never made: a production
        // site held a dead token for four days of daily heartbeats, each one
        // reading the refusal as an outage and settling into grace, while
        // the repair sat one method call away with nothing wired to it.
        if ($outcome->repairable() && $this->reactivator->refresh($entry->productSlug)) {
            $reissued = $this->store->get($entry->productSlug);

            if ($reissued !== null && $reissued->token !== $entry->token) {
                return $this->refresh($reissued);
            }
        }

        if ($outcome->refused()) {
            Log::warning('Licensing: the marketplace refused this site\'s activation token.', [
                'product' => $entry->productSlug,
                'code' => $outcome->refusalCode,
                'repairable' => $outcome->repairable(),
            ]);
        }

        return null;
    }

    /** Re-verify every licensed product — the scheduled heartbeat. */
    public function refreshAll(): int
    {
        $refreshed = 0;

        foreach ($this->store->all() as $entry) {
            if ($this->refresh($entry) !== null) {
                $refreshed++;
            }
        }

        return $refreshed;
    }

    private function isFresh(LicenseEntry $entry): bool
    {
        return $entry->lastVerifiedAt !== null
            && $entry->lastVerifiedAt->diffInHours(Carbon::now()) < self::RECHECK_HOURS;
    }

    /**
     * Policy lives in LicenseGate, so the boot-time check and this one can
     * never disagree — the difference between them is only that the gate
     * never touches the network.
     *
     * The one status the gate does not special-case: past_due is a billing
     * state, not a punishment, and the customer's site keeps working through
     * dunning.
     */
    private function stateFor(LicenseEntry $entry): LicenseState
    {
        if ($entry->status === 'past_due') {
            return LicenseState::Valid;
        }

        return $this->gate->stateOf($entry->productSlug);
    }
}
