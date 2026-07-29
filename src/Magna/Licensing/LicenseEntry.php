<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * One product's cached licence state on this site.
 *
 * `serverTime` is the anti-tamper anchor: the timestamp the marketplace
 * signed into its last response, never a local clock reading. Grace is
 * measured from it, and a local clock that has moved *backwards* past it is
 * treated as tampering (LicenseGuard).
 *
 * `licenseType` decides enforcement policy: a corporate or trial term that
 * ends stops the product, a marketplace term that lapses does not (see
 * LicenseState).
 *
 * `autoDisabled` records that LicenseEnforcer — not a human — turned the
 * plugin off, so the same enforcer may turn it back on when the licence
 * recovers, without ever re-enabling something an admin disabled by hand.
 */
class LicenseEntry
{
    public function __construct(
        public readonly string $productSlug,
        public readonly string $token,
        public readonly string $status,
        public readonly string $licenseType,
        public readonly ?Carbon $expiresAt,
        public readonly bool $updateEntitled,
        public readonly Carbon $serverTime,
        public readonly ?Carbon $lastVerifiedAt = null,
        public readonly bool $autoDisabled = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(string $productSlug, array $data): ?self
    {
        $token = $data['token'] ?? null;
        $serverTime = $data['server_time'] ?? null;

        if (! is_string($token) || $token === '' || ! is_string($serverTime)) {
            return null;
        }

        $parsedServerTime = self::parseOrNull($serverTime);

        // No trustworthy server-signed timestamp means grace cannot be aged
        // honestly, and every downstream decision depends on it — drop the
        // entry rather than substitute the local clock.
        if ($parsedServerTime === null) {
            return null;
        }

        return new self(
            productSlug: $productSlug,
            token: $token,
            status: is_string($data['status'] ?? null) ? $data['status'] : 'unknown',
            licenseType: is_string($data['license_type'] ?? null) ? $data['license_type'] : 'unknown',
            expiresAt: is_string($data['expires_at'] ?? null) ? self::parseOrNull($data['expires_at']) : null,
            updateEntitled: (bool) ($data['update_entitled'] ?? false),
            serverTime: $parsedServerTime,
            lastVerifiedAt: is_string($data['last_verified_at'] ?? null) ? self::parseOrNull($data['last_verified_at']) : null,
            autoDisabled: (bool) ($data['auto_disabled'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'status' => $this->status,
            'license_type' => $this->licenseType,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'update_entitled' => $this->updateEntitled,
            'server_time' => $this->serverTime->toIso8601String(),
            'last_verified_at' => $this->lastVerifiedAt?->toIso8601String(),
            'auto_disabled' => $this->autoDisabled,
        ];
    }

    /** @param array<string, mixed> $payload a verified /license/verify or /install response */
    public function withVerifiedPayload(array $payload): self
    {
        return new self(
            productSlug: $this->productSlug,
            token: $this->token,
            status: is_string($payload['status'] ?? null) ? $payload['status'] : $this->status,
            licenseType: is_string($payload['license_type'] ?? null) ? $payload['license_type'] : $this->licenseType,
            expiresAt: is_string($payload['license_expires_at'] ?? null) ? self::parseOrNull($payload['license_expires_at']) : null,
            updateEntitled: (bool) ($payload['update_entitled'] ?? false),
            serverTime: (is_string($payload['server_time'] ?? null) ? self::parseOrNull($payload['server_time']) : null) ?? $this->serverTime,
            lastVerifiedAt: Carbon::now(),
            autoDisabled: $this->autoDisabled,
        );
    }

    public function withAutoDisabled(bool $autoDisabled): self
    {
        return new self(
            productSlug: $this->productSlug,
            token: $this->token,
            status: $this->status,
            licenseType: $this->licenseType,
            expiresAt: $this->expiresAt,
            updateEntitled: $this->updateEntitled,
            serverTime: $this->serverTime,
            lastVerifiedAt: $this->lastVerifiedAt,
            autoDisabled: $autoDisabled,
        );
    }

    /**
     * Carbon::parse() throws on anything it cannot read, and this class is
     * constructed from two places that are not fully under our control: the
     * local encrypted cache (which can be corrupted or hand-edited) and a
     * remote payload. An exception from either would escape LicenseGate, which
     * PluginManager consults on EVERY request — one bad character in the cache
     * would take the whole site down with no way to fix it from the panel.
     */
    public static function parseDate(mixed $value): ?Carbon
    {
        return is_string($value) ? self::parseOrNull($value) : null;
    }

    private static function parseOrNull(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /** Corporate and trial terms stop the product when they end; marketplace terms do not. */
    public function endsOnExpiry(): bool
    {
        return $this->licenseType === 'corporate' || $this->licenseType === 'trial';
    }

    /** Human-readable reason for a locked product, for the admin banner. */
    public function reason(): string
    {
        return match ($this->status) {
            'revoked' => 'This licence was cancelled by the publisher.',
            'suspended' => 'This licence is suspended.',
            'expired' => $this->licenseType === 'trial'
                ? 'The free trial for this product has ended.'
                : 'This licence expired'.($this->expiresAt !== null ? ' on '.$this->expiresAt->format('d M Y') : '').'.',
            'past_due' => 'Payment for this licence is overdue.',
            default => 'This licence is no longer valid.',
        };
    }
}
