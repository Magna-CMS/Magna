<?php

declare(strict_types=1);

namespace Magna\Support;

use Composer\Autoload\ClassLoader;

/**
 * The Composer autoloader this process booted with, found once.
 *
 * Two places need to reach into it — PluginAutoloader, to teach it the PSR-4
 * prefixes of plugins Composer never saw, and StaleClassMap, to disarm classmap
 * entries pointing at files a core update deleted. Both used to (or would)
 * scan spl_autoload_functions() themselves; this is the one copy of that scan.
 *
 * Null on a process with no Composer loader registered, which only happens in
 * unit tests that build their own ClassLoader.
 */
final class ComposerLoader
{
    private static ?ClassLoader $loader = null;

    private static bool $resolved = false;

    public static function instance(): ?ClassLoader
    {
        if (self::$resolved) {
            return self::$loader;
        }

        self::$resolved = true;

        foreach (spl_autoload_functions() ?: [] as $function) {
            if (is_array($function) && $function[0] instanceof ClassLoader) {
                return self::$loader = $function[0];
            }
        }

        return null;
    }

    /** Forget the memoised answer so a test that registers its own loader is seen. */
    public static function reset(): void
    {
        self::$loader = null;
        self::$resolved = false;
    }
}
