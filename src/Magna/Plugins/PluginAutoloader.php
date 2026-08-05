<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Composer\Autoload\ClassLoader;
use JsonException;

/**
 * Registers an installed plugin's PSR-4 namespaces on the running Composer
 * autoloader.
 *
 * Composer normally does this: a plugin installed through `composer require`
 * (Marketplace) or a `type: path` repository is in vendor/composer/autoload_*.
 * A plugin installed by dropping files on disk — the Core Plugin Manager's zip
 * upload — is not, and on a host with no Composer binary there is nothing to
 * regenerate those maps. Its entry class then fails to autoload on the *next*
 * request, and bootEnabledPlugins() auto-disables the plugin as "files
 * missing".
 *
 * Registering here makes file-dropped plugins work with or without Composer.
 * It does not replace Composer: a plugin with third-party dependencies of its
 * own still needs `composer require` to fetch them.
 *
 * The classmap-authoritative problem, and why this class does not rely on
 * Composer alone: an authoritative classmap makes Composer's loader answer
 * "no" for any class not already in its map, WITHOUT consulting the PSR-4
 * rules registered here — so addPsr4() silently achieves nothing and a
 * freshly installed plugin's entry class is unfindable. Release archives no
 * longer ship that flag, but CoreUpdater deliberately never replaces
 * vendor/ (a customer's vendor/ holds packages a core release knows nothing
 * about), so every site that UPDATED from an older archive keeps the
 * authoritative loader forever. That is not a state a plugin install can
 * repair on a host with no Composer binary.
 *
 * So the prefixes are also registered on an autoloader of our own, appended
 * after Composer's. It only ever answers for prefixes belonging to installed
 * plugins, and only when Composer has already declined — no cost to any
 * other class lookup, and correct whatever mode Composer is in.
 */
final class PluginAutoloader
{
    /** @var array<string, true> Prefix+path pairs already registered this process. */
    private array $registered = [];

    /** @var array<string, list<string>> PSR-4 prefix => absolute directories, for our own loader. */
    private array $prefixes = [];

    private bool $fallbackRegistered = false;

    private ?ClassLoader $loader = null;

    private bool $resolved = false;

    /**
     * Idempotent: safe to call for every enabled plugin on every boot.
     */
    public function register(string $pluginBasePath): void
    {
        $composerJson = rtrim($pluginBasePath, '/\\').'/composer.json';
        if (! is_file($composerJson)) {
            return;
        }

        $raw = file_get_contents($composerJson);
        if ($raw === false) {
            return;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        $autoload = $decoded['autoload'] ?? null;
        if (! is_array($autoload) || ! is_array($autoload['psr-4'] ?? null)) {
            return;
        }

        /** @var array<string, string|list<string>> $psr4 */
        $psr4 = $autoload['psr-4'];

        $loader = $this->loader();

        $root = realpath($pluginBasePath);
        if ($root === false) {
            return;
        }
        $root = str_replace('\\', '/', $root);

        foreach ($psr4 as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $absolute = realpath(rtrim($pluginBasePath, '/\\').'/'.ltrim((string) $path, '/'));
                if ($absolute === false || ! is_dir($absolute)) {
                    continue;
                }
                $absolute = str_replace('\\', '/', $absolute);

                // The autoload map lives in the plugin's own composer.json,
                // which arrives inside an uploaded zip. A path of "../../.."
                // would map a namespace onto application source and let a
                // plugin's classname resolve to core files it does not own.
                if ($absolute !== $root && ! str_starts_with($absolute, $root.'/')) {
                    continue;
                }

                $key = $prefix.'|'.$absolute;
                if (isset($this->registered[$key])) {
                    continue;
                }

                // Composer's own map first — free and correct when it is not
                // in authoritative mode.
                $loader?->addPsr4($prefix, $absolute);

                // And ours, which answers whatever mode Composer is in.
                $this->prefixes[$prefix][] = $absolute;
                $this->registerFallback();

                $this->registered[$key] = true;
            }
        }
    }

    /**
     * Append our own PSR-4 resolver, once. Appended rather than prepended so
     * Composer answers first for everything it knows; this only ever runs for
     * classes nothing else could load, and returns immediately unless the
     * name starts with a prefix an installed plugin registered.
     */
    private function registerFallback(): void
    {
        if ($this->fallbackRegistered) {
            return;
        }

        $this->fallbackRegistered = true;

        spl_autoload_register(function (string $class): void {
            foreach ($this->prefixes as $prefix => $directories) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                foreach ($directories as $directory) {
                    $file = $directory.'/'.$relative;

                    if (is_file($file)) {
                        require $file;

                        return;
                    }
                }
            }
        });
    }

    private function loader(): ?ClassLoader
    {
        if ($this->resolved) {
            return $this->loader;
        }

        $this->resolved = true;

        foreach (spl_autoload_functions() ?: [] as $function) {
            if (is_array($function) && $function[0] instanceof ClassLoader) {
                return $this->loader = $function[0];
            }
        }

        return null;
    }
}
