<?php

declare(strict_types=1);

/**
 * Fallback PSR-4 loader for Magna's own classes.
 *
 * A core update overlays `src/Magna` but deliberately never replaces
 * `vendor/` — a customer's vendor tree may hold packages a core release knows
 * nothing about. Composer's autoloader in an installed site is therefore the
 * one generated when that site was installed, and a release that adds a *new*
 * core class is invisible to it: an optimized classmap answers "no class"
 * without ever looking at the PSR-4 rules, and an authoritative one refuses to
 * look on principle. The update lands, the class file is sitting right there
 * on disk, and every request dies with
 *
 *     Class "Magna\…" not found
 *
 * This is registered after Composer's loader, so it is consulted only for
 * `Magna\` classes Composer could not resolve — normally never. It lives in
 * `bootstrap/` (also overlaid by updates) and is required by path rather than
 * autoloaded, because an autoloader cannot bootstrap itself.
 *
 * Returns a factory so the mapping can be exercised against a fixture
 * directory in tests instead of the real `src/Magna`.
 *
 * @return callable(string): callable(string): void
 */
return static function (string $baseDir): callable {
    $prefix = 'Magna\\';
    $baseDir = rtrim($baseDir, '/\\');

    return static function (string $class) use ($prefix, $baseDir): void {
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));

        // A class name is normally trusted, but this one is turned straight
        // into a filesystem path — refuse anything that could climb out of
        // src/Magna rather than resolving it.
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            return;
        }

        $path = $baseDir.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';

        if (is_file($path)) {
            require $path;
        }
    };
};
