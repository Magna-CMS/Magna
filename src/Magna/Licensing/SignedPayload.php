<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Magna\Marketplace\Marketplace;
use Throwable;

/**
 * Verifies the Ed25519 envelope every licence response arrives in.
 *
 * This is the control that makes "point the site at a fake licence server"
 * pointless: a spoofed host can return `{"valid": true}` all day, but it
 * cannot produce a signature that validates against the public key baked
 * into core. Nothing in this namespace may act on a licence payload that has
 * not passed through here.
 *
 * Envelope: {"data": base64(json), "signature": base64(sig), "algorithm": "ed25519"}.
 * The signature covers the base64 STRING, not the decoded JSON — so a
 * re-encode on this side (key order, escaping) can never break verification.
 */
class SignedPayload
{
    /**
     * @param  array<string, mixed>  $envelope
     * @return array<array-key, mixed>|null the decoded payload, or null if the
     *                                      envelope is malformed or unsigned/forged
     */
    public static function open(array $envelope): ?array
    {
        $data = $envelope['data'] ?? null;
        $signature = $envelope['signature'] ?? null;

        if (! is_string($data) || ! is_string($signature)) {
            return null;
        }

        if (($envelope['algorithm'] ?? 'ed25519') !== 'ed25519') {
            return null;
        }

        if (! self::verify($signature, $data)) {
            return null;
        }

        $json = base64_decode($data, true);

        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Verify a detached signature over an arbitrary signed string — used for
     * the envelope above and for the package checksum inside a download
     * grant, which is signed the same way.
     */
    public static function verify(string $base64Signature, string $signedString): bool
    {
        $publicKey = base64_decode(Marketplace::licensePublicKey(), true);
        $signature = base64_decode($base64Signature, true);

        if ($publicKey === false || $signature === false || $publicKey === '' || $signature === '') {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $signedString, $publicKey);
        } catch (Throwable) {
            // Wrong key length, wrong signature length — malformed input is
            // a failed verification, never an exception that reaches the UI.
            return false;
        }
    }

    /**
     * Rebuild the exact string the marketplace signed for a single-value
     * payload (currently the package sha256 in a download grant), so the
     * checksum can be verified without a second round trip.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function canonicalize(array $payload): string
    {
        return base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
