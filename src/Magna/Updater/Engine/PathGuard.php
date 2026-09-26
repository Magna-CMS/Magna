<?php

declare(strict_types=1);

namespace Magna\Updater\Engine;

/**
 * What a release may and may never touch on an installed site.
 *
 * A release manifest names the paths it owns, and a release engine acts on
 * them — new code, executed by the site's current updater. The manifest and
 * engine travel inside a signed archive, so they are as trusted as the code
 * they ship; this guard is not a boundary against an attacker who holds the
 * signing key. It is a boundary against a release that is simply wrong: a
 * builder that listed `config` where it meant `config/defaults`, a manifest
 * naming `storage`. Those mistakes must not be able to erase a site's
 * uploads, its `.env`, or the plugins it bought — whatever the archive says.
 *
 * Two lists, longest match wins. The allowlist is every prefix core may own;
 * the denylist is every prefix a site owns even where it sits under an
 * allowed one (`bootstrap/cache` under `bootstrap`). A prefix is added to the
 * allowlist one release BEFORE a manifest names it, because the guard that
 * checks a manifest is the one already installed.
 *
 * `vendor`, `composer.json` and `composer.lock` are the one exception with a
 * switch: denied to a manifest's path list, allowed to the engine's vendor
 * step alone — and only there, because that step is what decides whether the
 * site's vendor/ holds packages the release knows nothing about.
 */
final class PathGuard
{
    /** @var list<string> */
    public const ALLOWED_PREFIXES = [
        'app',
        'artisan',
        'bootstrap',
        'bundled/magna-cms/plugin-sdk',
        'config/defaults',
        'database/migrations',
        'database/seeders',
        'public/build',
        'public/fonts',
        'routes',
        'src/Magna',
        'vendor/magna-cms/plugin-sdk',
    ];

    /** @var list<string> */
    public const DENIED_PREFIXES = [
        '.env',
        '.git',
        'bootstrap/cache',
        'composer.json',
        'composer.lock',
        'config',
        'database/database.sqlite',
        'node_modules',
        'plugins-dev',
        'public/media',
        'public/storage',
        'public/uploads',
        'storage',
        'themes',
        'vendor',
    ];

    /** @var list<string> What the engine's vendor step, and nothing else, may replace whole. */
    public const VENDOR_PATHS = ['composer.json', 'composer.lock', 'vendor'];

    /** Longest relative path a manifest may name; longer is a mistake, not a layout. */
    public const MAX_PATH_LENGTH = 120;

    /**
     * The path in the one spelling the guard reasons about, or null when it
     * could never be a relative path inside the install: absolute, a drive,
     * a `..` segment, a backslash, a NUL byte, or nothing at all.
     */
    public static function normalize(string $relative): ?string
    {
        // Before trim(): its default mask strips NUL bytes, which would turn
        // "src/Magna\0" into a path that passes.
        if (str_contains($relative, "\0") || str_contains($relative, '\\')) {
            return null;
        }

        $path = trim($relative, " \t\n\r");

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return null;
        }

        return implode('/', $segments);
    }

    /**
     * Why a manifest may not name this path as core-owned, or null when it may.
     *
     * @param  bool  $vendorAllowed  true only from the engine's vendor step
     */
    public function reject(string $relative, bool $vendorAllowed = false): ?string
    {
        $path = self::normalize($relative);

        if ($path === null) {
            return "\"{$relative}\" is not a relative path inside the install.";
        }

        if (strlen($path) > self::MAX_PATH_LENGTH) {
            return "\"{$path}\" is longer than a core path can be.";
        }

        if ($vendorAllowed && in_array($path, self::VENDOR_PATHS, true)) {
            return null;
        }

        $allowed = self::longestMatch($path, self::ALLOWED_PREFIXES);
        $denied = self::longestMatch($path, self::DENIED_PREFIXES);

        if ($denied !== null && ($allowed === null || strlen($denied) >= strlen($allowed))) {
            return "\"{$path}\" is a site-owned path (under \"{$denied}\") and an update may never replace it.";
        }

        if ($allowed === null) {
            return "\"{$path}\" is not under any path an update may own.";
        }

        return null;
    }

    public function isCoreOwnable(string $relative, bool $vendorAllowed = false): bool
    {
        return $this->reject($relative, $vendorAllowed) === null;
    }

    /**
     * The longest prefix in $prefixes that is the path itself or one of its
     * ancestors — a prefix matches on segment boundaries only, so `app` does
     * not claim `application/`.
     *
     * @param  list<string>  $prefixes
     */
    private static function longestMatch(string $path, array $prefixes): ?string
    {
        $best = null;

        foreach ($prefixes as $prefix) {
            if ($path !== $prefix && ! str_starts_with($path, $prefix.'/')) {
                continue;
            }

            if ($best === null || strlen($prefix) > strlen($best)) {
                $best = $prefix;
            }
        }

        return $best;
    }
}
