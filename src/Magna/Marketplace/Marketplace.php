<?php

declare(strict_types=1);

namespace Magna\Marketplace;

/**
 * Compile-time constants for the official Magna plugin marketplace.
 *
 * The API base URL is intentionally a hardcoded constant (not a config value)
 * so every Magna install points at the official registry by default.
 */
final class Marketplace
{
    /** Origin of the marketplace server — the browser-facing legs of Account Centre's connect handshake redirect here directly (not through /api/v1). */
    public const WEB_BASE = 'https://managemagna.jrstudios.dev';

    /** Base URL of the marketplace catalog API (v1). */
    public const API_BASE = self::WEB_BASE.'/api/v1';

    /** How long a catalog response is cached, in seconds. */
    public const CACHE_TTL = 3600;

    /** HTTP request timeout, in seconds. */
    public const REQUEST_TIMEOUT = 8;

    /** Cache key for the full catalog listing. */
    public const CACHE_KEY = 'magna.marketplace.plugins';

    /**
     * Ed25519 public key (base64) that every licence response is verified
     * against — see Magna\Licensing\SignedPayload.
     *
     * Baked into core on purpose, exactly like API_BASE: a site operator who
     * could point this at their own key could mint "valid" licence responses
     * locally. The private half lives only on the marketplace server
     * (MARKETPLACE_LICENSE_SIGNING_SECRET).
     */
    public const LICENSE_PUBLIC_KEY = 'ogqnrv36ejgQZm14T+TrEyiDzG2oGOOjxXRpoVJIcRg=';

    /** Ed25519 public keys are exactly 32 bytes once base64-decoded. */
    private const LICENSE_PUBLIC_KEY_BYTES = 32;

    /**
     * The key in force. The config override exists for tests and for a
     * pre-release marketplace running its own keypair — it is deliberately
     * NOT an env var read at the call site, and `config/magna.php` only honours
     * MAGNA_LICENSE_PUBLIC_KEY outside production, so a production build ships
     * the constant above and nothing else.
     */
    public static function licensePublicKey(): string
    {
        $configured = config('magna.licensing.public_key');

        return is_string($configured) && $configured !== '' ? $configured : self::LICENSE_PUBLIC_KEY;
    }

    /**
     * Whether the key in force is structurally usable.
     *
     * An empty or malformed key makes every signature check fail, which is
     * safe (nothing forged is ever accepted) but silent: the admin sees
     * "could not reach the licence server" forever while the real cause is a
     * build that shipped without its key. Callers surface that difference —
     * see Magna\System\SystemHealthCollector and VerifyLicensesCommand.
     */
    public static function hasUsableLicenseKey(): bool
    {
        $decoded = base64_decode(self::licensePublicKey(), true);

        return is_string($decoded) && strlen($decoded) === self::LICENSE_PUBLIC_KEY_BYTES;
    }
}
