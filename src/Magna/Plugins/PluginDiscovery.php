<?php

declare(strict_types=1);

namespace Magna\Plugins;

use JsonException;
use Magna\Plugins\Exceptions\InvalidManifestException;

/**
 * Locates Magna plugins from two sources:
 *   1. vendor/composer/installed.json — packages with "type": "magna-plugin"
 *   2. plugins-dev/{vendor}/{package}/magna.json — local development path repositories
 */
final class PluginDiscovery
{
    /**
     * Per-request memoized result. Discovery reads vendor/composer/installed.json
     * and globs plugins-dev/, and is called several times per request (plugin
     * boot, the Plugins page, the Core Plugin Manager page). Since the resolver
     * is bound as a singleton, caching here collapses that repeated filesystem
     * I/O to one scan per request — important as the plugin count grows. Reset()
     * busts it after an install/uninstall that changes what is on disk.
     *
     * @var list<PluginInfo>|null
     */
    private ?array $cache = null;

    public function __construct(private readonly string $basePath) {}

    /**
     * @return list<PluginInfo>
     */
    public function discover(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $vendor = $this->discoverFromVendor();
        $dev = $this->discoverFromDev();

        // Merge; plugins-dev/ takes precedence over vendor (same name wins for dev).
        $byName = [];
        foreach ([...$vendor, ...$dev] as $info) {
            $byName[$info->manifest->name] = $info;
        }

        return $this->cache = array_values($byName);
    }

    /**
     * Forget the memoized scan — call after an install/uninstall so a later
     * discover() in the same request reflects what is now on disk.
     */
    public function reset(): void
    {
        $this->cache = null;
    }

    public function find(string $name): ?PluginInfo
    {
        foreach ($this->discover() as $info) {
            if ($info->manifest->name === $name) {
                return $info;
            }
        }

        return null;
    }

    /**
     * @return list<PluginInfo>
     */
    private function discoverFromVendor(): array
    {
        $installedJson = $this->basePath.'/vendor/composer/installed.json';
        if (! file_exists($installedJson)) {
            return [];
        }

        $raw = file_get_contents($installedJson);
        if ($raw === false) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $decoded = is_array($decoded) ? $decoded : [];
        $packagesNode = $decoded['packages'] ?? $decoded;
        $packages = is_array($packagesNode) ? $packagesNode : [];

        $result = [];
        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }
            if (($package['type'] ?? '') !== 'magna-plugin') {
                continue;
            }

            $pkgName = is_string($package['name'] ?? null) ? $package['name'] : null;
            if ($pkgName === null) {
                continue;
            }

            $pkgPath = $this->basePath.'/vendor/'.$pkgName;
            $manifestPath = $pkgPath.'/magna.json';
            if (! file_exists($manifestPath)) {
                continue;
            }

            try {
                $info = $this->loadFrom($manifestPath, $pkgPath);
                $result[] = $info;
            } catch (InvalidManifestException) {
                // Silently skip malformed manifests during discovery.
            }
        }

        return $result;
    }

    /**
     * S1-19: this glob previously picked up ANY plugins-dev/{vendor}/{package}
     * directory with a magna.json, regardless of whether the package was
     * actually wired into root composer.json — undermining Stage 0's
     * assumption that Composer wiring is the trust boundary for what's
     * installable (e.g. a "seo" example plugin with no composer wiring at
     * all was still discoverable/installable via `magna:plugin:enable`).
     * Now requires BOTH: (a) not running in production (this discovery path
     * is explicitly for local dev path repositories per its own docblock —
     * never active in a real deployment regardless of what's on disk), and
     * (b) the manifest's declared package name actually appears in root
     * composer.json's require/require-dev, matching what Composer itself
     * considers installed.
     *
     * @return list<PluginInfo>
     */
    private function discoverFromDev(): array
    {
        if (app()->environment('production')) {
            return [];
        }

        $devDir = $this->basePath.'/plugins-dev';
        if (! is_dir($devDir)) {
            return [];
        }

        $wiredPaths = $this->composerPathRepositoryDirs();

        $result = [];
        foreach (glob($devDir.'/*/*/magna.json') ?: [] as $manifestPath) {
            $pkgPath = dirname($manifestPath);

            if (! in_array($this->normalizePath($pkgPath), $wiredPaths, true)) {
                continue;
            }

            try {
                $result[] = $this->loadFrom($manifestPath, $pkgPath);
            } catch (InvalidManifestException) {
                // Skip malformed manifests during discovery.
            }
        }

        return $result;
    }

    /**
     * Absolute, normalized directory paths of every `"type": "path"` entry
     * in root composer.json's `repositories` array — the actual Composer
     * trust boundary for "is this plugins-dev directory wired in", since
     * that's what makes a `magna:` require Composer would actually resolve
     * against this specific path (a magna.json's internal `name` field
     * doesn't necessarily match its Composer package name, so comparing
     * against require/require-dev keys directly isn't reliable).
     *
     * @return list<string>
     */
    private function composerPathRepositoryDirs(): array
    {
        $composerJsonPath = $this->basePath.'/composer.json';
        if (! file_exists($composerJsonPath)) {
            return [];
        }

        $raw = file_get_contents($composerJsonPath);
        if ($raw === false) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $repositories = is_array($decoded['repositories'] ?? null) ? $decoded['repositories'] : [];

        $dirs = [];
        foreach ($repositories as $repo) {
            if (! is_array($repo) || ($repo['type'] ?? null) !== 'path' || ! is_string($repo['url'] ?? null)) {
                continue;
            }

            $dirs[] = $this->normalizePath($this->basePath.'/'.$repo['url']);
        }

        return $dirs;
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);

        return $real !== false ? str_replace('\\', '/', $real) : str_replace('\\', '/', $path);
    }

    /**
     * @throws InvalidManifestException
     */
    private function loadFrom(string $manifestPath, string $pkgPath): PluginInfo
    {
        $manifest = Manifest::loadFromFile($manifestPath);

        return new PluginInfo($manifest, realpath($pkgPath) ?: $pkgPath);
    }
}
