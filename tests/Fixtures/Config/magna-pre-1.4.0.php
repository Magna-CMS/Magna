<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Installation
    |--------------------------------------------------------------------------
    |
    | The web installer runs until the lock file exists, then its routes
    | return 404 forever. `installed_override` short-circuits the check —
    | used by the test suite (MAGNA_INSTALLED=true) so application tests
    | run as an installed site.
    |
    */

    'installed_override' => env('MAGNA_INSTALLED'),

    'install' => [
        'lock_path' => storage_path('app/magna-installed.json'),
        'env_path' => base_path('.env'),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Registration
    |--------------------------------------------------------------------------
    | Disabled by default per security-spec §1. Enable only when self-service
    | sign-up is intentional. Migrates to the typed Settings system at Stage 3.
    */
    'registration_enabled' => env('MAGNA_REGISTRATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Themes directory
    |--------------------------------------------------------------------------
    | Where installed theme packages live (themes/{vendor}/{name}). Only
    | overridden in tests; production installs use the repository default.
    */
    'themes_path' => env('MAGNA_THEMES_PATH'),

    /*
    |--------------------------------------------------------------------------
    | API Token Expiry (days)
    |--------------------------------------------------------------------------
    */
    'token_expiry' => [
        'delivery' => (int) env('MAGNA_TOKEN_EXPIRY_DELIVERY', 365),
        'management' => (int) env('MAGNA_TOKEN_EXPIRY_MANAGEMENT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Token Default Rate Limits (requests per minute)
    |--------------------------------------------------------------------------
    */
    'token_rate_limit' => [
        'delivery' => (int) env('MAGNA_TOKEN_RATE_LIMIT_DELIVERY', 1000),
        'management' => (int) env('MAGNA_TOKEN_RATE_LIMIT_MANAGEMENT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    | window: how many 30-second steps either side of "now" are accepted. Each
    |   extra step multiplies the codes valid at any instant — and so the odds
    |   for an online brute-force. Raise only if users report clock-drift
    |   failures.
    */
    'two_factor' => [
        'issuer' => env('APP_NAME', 'Magna CMS'),
        'recovery_codes' => (int) env('MAGNA_2FA_RECOVERY_CODES', 8),
        'window' => (int) env('MAGNA_2FA_WINDOW', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Policy
    |--------------------------------------------------------------------------
    | Applied by Magna\Auth\PasswordRules to registration and password reset
    | alike, so the two can never drift apart.
    |
    | check_compromised queries the Have I Been Pwned range API with a 5-char
    | hash prefix (the password itself never leaves the server). Turn it off on
    | installs with no outbound network access, where it only adds a timeout.
    */
    'password' => [
        'min_length' => (int) env('MAGNA_PASSWORD_MIN_LENGTH', 12),
        'require_symbols' => (bool) env('MAGNA_PASSWORD_REQUIRE_SYMBOLS', false),
        'check_compromised' => (bool) env('MAGNA_PASSWORD_CHECK_COMPROMISED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Login Brute-Force Protection
    |--------------------------------------------------------------------------
    | max_attempts: consecutive failures before lockout engages.
    | base_lockout_seconds: first lockout duration; doubles per window
    |   (exponential backoff), capped at max_lockout_seconds.
    */
    'login' => [
        'max_attempts' => (int) env('MAGNA_LOGIN_MAX_ATTEMPTS', 5),
        'base_lockout_seconds' => (int) env('MAGNA_LOGIN_BASE_LOCKOUT_SECONDS', 30),
        'max_lockout_seconds' => (int) env('MAGNA_LOGIN_MAX_LOCKOUT_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Licensing
    |--------------------------------------------------------------------------
    | public_key: Ed25519 public key (base64) that every licence response is
    |   verified against. The key in force is normally the constant baked into
    |   Magna\Marketplace\Marketplace — a site operator who could point this at
    |   their own key could mint "valid" licence responses locally, so the env
    |   override is honoured ONLY outside production, where it exists for the
    |   test suite and for a pre-release marketplace running its own keypair.
    |
    | require_signed_checksum: refuse a core update whose sha256 arrives without
    |   a valid Ed25519 signature. The checksum alone only defeats an attacker
    |   who can swap the archive but not the /updates response; the signature
    |   also defeats one who can forge both. Enable once Update Manager
    |   publishes `zip_sha256_signature` for every release.
    */
    'licensing' => [
        'public_key' => env('APP_ENV') === 'production' ? '' : env('MAGNA_LICENSE_PUBLIC_KEY', ''),
    ],

    'account_centre' => [
        // Refuse an account-exchange response that is not Ed25519-signed.
        // Enable once Update Manager wraps /account/exchange in a signed
        // envelope; an invalid signature is refused either way.
        'require_signed_exchange' => (bool) env('MAGNA_ACCOUNT_REQUIRE_SIGNED_EXCHANGE', false),
    ],

    'updater' => [
        'require_signed_checksum' => (bool) env('MAGNA_UPDATER_REQUIRE_SIGNED_CHECKSUM', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Edge Cache
    |--------------------------------------------------------------------------
    | driver: null (default) | cloudflare | fastly | varnish
    |
    | 'null'       — no-op; safe for local dev and environments without a CDN.
    | 'cloudflare' — requires CLOUDFLARE_ZONE_ID + CLOUDFLARE_API_TOKEN.
    | 'fastly'     — requires FASTLY_SERVICE_ID + FASTLY_API_TOKEN.
    | 'varnish'    — requires VARNISH_HOST (+ optional VARNISH_SECRET).
    */
    'edge_cache' => [
        'driver' => env('MAGNA_EDGE_CACHE_DRIVER', 'null'),
        'cloudflare' => [
            'zone_id' => env('CLOUDFLARE_ZONE_ID', ''),
            'api_token' => env('CLOUDFLARE_API_TOKEN', ''),
        ],
        'fastly' => [
            'service_id' => env('FASTLY_SERVICE_ID', ''),
            'api_token' => env('FASTLY_API_TOKEN', ''),
        ],
        'varnish' => [
            'host' => env('VARNISH_HOST', ''),
            'secret' => env('VARNISH_SECRET', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Render budget
    |--------------------------------------------------------------------------
    | What one page render may spend on plugin resolvers — dynamic tags and
    | data sources, which are third-party code running inside a visitor's
    | request. A resolver that throws already degrades to a gap; these are the
    | ceilings for one that is merely slow, and for a page that asks for more
    | of them than anybody intended.
    |
    | Past either ceiling the remaining resolvers are refused, the page renders
    | the same gaps it would for a resolver that failed, and the result is NOT
    | cached — a degraded copy in the shared cache would outlive the slow
    | minute that produced it.
    |
    | Enforcement happens between invocations. A single resolver that hangs for
    | ever is the web server's timeout to deal with; PHP cannot interrupt it.
    */
    'render_budget' => [
        'max_resolvers' => (int) env('MAGNA_RENDER_MAX_RESOLVERS', 200),
        'max_milliseconds' => (int) env('MAGNA_RENDER_MAX_MS', 750),

        // One call above this is logged by name, so a plugin author hears
        // about it long before the ceiling starts refusing work.
        'slow_milliseconds' => (int) env('MAGNA_RENDER_SLOW_MS', 250),
    ],

];
