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
 * Note the classmap-authoritative caveat: an authoritative classmap disables
 * PSR-4 lookups entirely, so a class it does not already know stays
 * unreachable no matter what is registered here. Release archives that expect
 * to receive plugin uploads must not be built with that flag (see the hub
 * profile in bin/build-release.php).
 */
final class PluginAutoloader
{
    /** @var array<string, true> Prefix+path pairs already registered this process. */
    private array $registered = [];

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
        if ($loader === null) {
            return;
        }

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

                $loader->addPsr4($prefix, $absolute);
                $this->registered[$key] = true;
            }
        }
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
