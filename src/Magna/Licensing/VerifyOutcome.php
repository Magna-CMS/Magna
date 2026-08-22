<?php

declare(strict_types=1);

namespace Magna\Licensing;

/**
 * What a licence verification actually said, with refusal and outage apart.
 *
 * verify() answered null for both, and the two demand opposite reactions. An
 * outage means wait: the licence is presumed fine, grace covers the gap, and
 * hammering a down server helps nobody. A refusal means act: the server was
 * reached, it understood the token, and it said no — waiting changes nothing,
 * ever. Folding them into one null is how a production site sat for four days
 * holding a token the marketplace had stopped recognising, indistinguishable
 * from a network blip, while the repair that would have fixed it in one call
 * sat unwired because nothing could tell it when to run.
 *
 * A response whose signature does not verify stays an outage on purpose — a
 * forged "refusal" must not be able to talk a site into abandoning its token.
 * Only a refusal the marketplace signed nothing of — a plain HTTP error with
 * an error code — is refusal-shaped, and the only action it can trigger is
 * asking the marketplace itself for a fresh activation, which a forger gains
 * nothing from.
 */
final readonly class VerifyOutcome
{
    /**
     * Refusal codes a fresh activation from the connected account repairs.
     *
     * `invalid_token`: the token is gone from the marketplace's side — a
     * reset, a migration, a released seat. `fingerprint_mismatch`: the token
     * exists but belongs to another install — a site restored onto new
     * infrastructure. Both are cured by minting a new activation for this
     * site; nothing else is. An expired or revoked licence is a fact about
     * the entitlement, not the token, and re-activating cannot and must not
     * paper over it.
     */
    private const REPAIRABLE = ['invalid_token', 'fingerprint_mismatch'];

    /**
     * @param  array<string, mixed>|null  $payload  the verified, signature-checked payload
     * @param  string|null  $refusalCode  the marketplace's error code, when it answered and said no
     */
    public function __construct(
        public ?array $payload,
        public ?string $refusalCode = null,
    ) {}

    public function verified(): bool
    {
        return $this->payload !== null;
    }

    /** The server was reached and said no — waiting will not change the answer. */
    public function refused(): bool
    {
        return $this->payload === null && $this->refusalCode !== null;
    }

    /** No usable answer at all: unreachable, or a response that failed verification. */
    public function outage(): bool
    {
        return $this->payload === null && $this->refusalCode === null;
    }

    /** Whether a fresh activation from the connected account cures this refusal. */
    public function repairable(): bool
    {
        return $this->refusalCode !== null && in_array($this->refusalCode, self::REPAIRABLE, true);
    }
}
