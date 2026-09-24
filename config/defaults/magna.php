<?php

/*
|--------------------------------------------------------------------------
| Magna's own configuration - the copy that travels with core
|--------------------------------------------------------------------------
|
| This is the real file. `config/magna.php` returns it, so a site that has
| never customised anything has a one-line config file and always holds
| whatever the installed release believes.
|
| It lives in its own directory because `config/defaults` is a core-owned
| update path and `config/` itself deliberately is not - an update that reset
| somebody's mail driver to ship a feature flag would be the worse bug. The
| cost of that trade went unnoticed for two releases: a key added to
| `config/magna.php` reached a fresh install and never reached an updated one,
| so the code arrived, the key did not, and `config()` answered null. Two
| security controls shipped inert that way. Nothing here is a site's to edit,
| so replacing the whole directory on an update takes nothing from anybody.
| See Magna\Support\ConfigDefaults.
|
| A site that DOES customise replaces `config/magna.php` with its own array.
| Anything it omits is backfilled from here at boot, so it keeps its choices
| and still receives keys added since it was written.
|
| To decline a default, set the key to `null` rather than deleting it. A
| deleted key is indistinguishable from one your file predates, and core will
| fill it back in; an explicit null is left alone forever.
*/

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
    | Debug Window
    |--------------------------------------------------------------------------
    |
    | Where the expiry of a panel-opened debug session is stamped. APP_DEBUG
    | shows stack traces, SQL and environment dumps to every visitor, so the
    | panel may only turn it on for a bounded window; the next request after
    | the stamp expires turns it back off. Deleting this file by hand simply
    | means the flag stays wherever .env leaves it.
    |
    */

    'debug_window' => [
        'stamp_path' => storage_path('app/magna-debug-window.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings Storage
    |--------------------------------------------------------------------------
    |
    | Which cache store holds the settings snapshot. Deliberately NOT
    | `cache.default`: the cache driver is itself an admin-editable setting,
    | so reading the settings through the store they control is circular.
    |
    | PerformanceServiceProvider has to read the settings to learn which
    | driver to switch to, which means the read happens on the OLD store and
    | the later save busts the NEW one. A driver change then sat stale until
    | the hour-long snapshot expired — visible as Performance Settings
    | reporting Redis while System Insights still reported the database.
    |
    | `file` needs neither a database nor Redis, so it also works before the
    | installer has run. Point this at `database` or `redis` on a multi-node
    | deployment, where a per-node file cache would let one node keep serving
    | a stale snapshot after another node saved.
    |
    */

    'settings' => [
        'cache_store' => env('MAGNA_SETTINGS_CACHE_STORE', 'file'),
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
    | Media
    |--------------------------------------------------------------------------
    | Which filesystem disk uploads are ingested onto. Read by
    | MediaServiceProvider since it was written, and shipped by no config
    | file until now - so it has never been settable without editing code,
    | and its `public` fallback was the only value any install could have.
    */
    'media' => [
        'disk' => env('MAGNA_MEDIA_DISK', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Development plugins directory
    |--------------------------------------------------------------------------
    | Where licensed installs place plugin packages
    | (plugins-dev/{vendor}/{package}). Only overridden in tests, which must
    | never write into the repository's real plugins-dev/ tree; production
    | installs use the repository default.
    */
    'plugins' => [
        'dev_path' => env('MAGNA_PLUGINS_DEV_PATH'),
    ],

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
    |   also defeats one who can forge both. ON by default since Update
    |   Manager publishes the signature for every core release (verified in
    |   live check-in data); MAGNA_UPDATER_REQUIRE_SIGNED_CHECKSUM=false is
    |   the escape hatch for an install talking to a hub that does not sign.
    */
    /*
    |--------------------------------------------------------------------------
    | Trusted Hosts
    |--------------------------------------------------------------------------
    | Extra hostnames this install is legitimately served on, beyond APP_URL's
    | host and its subdomains — a LAN address, a staging alias, a panel on its
    | own hostname. Comma-separated. Everything else is refused with a 400
    | before any absolute URL (password-reset links included) can be built
    | from a spoofed Host or X-Forwarded-Host header; see the trustHosts()
    | registration in bootstrap/app.php.
    */
    'security' => [
        'trusted_hosts' => env('MAGNA_TRUSTED_HOSTS', ''),
    ],

    'licensing' => [
        'public_key' => env('APP_ENV') === 'production' ? '' : env('MAGNA_LICENSE_PUBLIC_KEY', ''),
    ],

    'account_centre' => [
        // Refuse an account-exchange response that is not Ed25519-signed.
        // Enable once Update Manager wraps /account/exchange in a signed
        // envelope; an invalid signature is refused either way.
        'require_signed_exchange' => (bool) env('MAGNA_ACCOUNT_REQUIRE_SIGNED_EXCHANGE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Updater
    |--------------------------------------------------------------------------
    | Core updates are refused unless the release's checksum carries a valid
    | Ed25519 signature from the marketplace. Turn this on only for a private
    | update server that does not sign.
    |
    | Renamed from `require_signed_checksum`, which cannot simply change its
    | default. That key shipped as false before 1.4.0 and true after, and a
    | core update never replaces config/ - so every site that updated kept the
    | old false and accepted unsigned releases, while a fresh install of the
    | same version refused them. A present key is the site's own word and core
    | will not overrule it; a renamed key is absent everywhere, so the safe
    | value reaches every install. The inversion is deliberate too: the unsafe
    | choice now has to be typed out.
    */
    'updater' => [
        'allow_unsigned_checksum' => (bool) env('MAGNA_UPDATER_ALLOW_UNSIGNED_CHECKSUM', false),

        /*
         * Retired, and left here on purpose.
         *
         * ReleaseArchive reads it once, to notice a site that had deliberately
         * turned enforcement off under the old name and tell them why updates
         * have started being refused. Declaring it keeps that a read of a key
         * this file defines, which is what the delivery guard in
         * tests/Feature/Updater/CoreConfigDefaultsTest.php insists on.
         *
         * Null, not false: a site that set it keeps its own value and gets the
         * notice; everybody else has nothing to be told about.
         */
        'require_signed_checksum' => null,
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
