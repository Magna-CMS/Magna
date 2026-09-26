<?php

declare(strict_types=1);

namespace Magna\Support;

use Composer\Autoload\ClassLoader;

/**
 * Disarms classmap entries that point at core files a release deleted.
 *
 * A core update overlays `src/Magna` and `app` and never `vendor/`, so the
 * optimised classmap in vendor/composer describes the tree the site was
 * INSTALLED with, forever. Composer's ClassLoader::findFile() answers from that
 * map before consulting any PSR-4 rule and never checks the file exists, so a
 * class a later release deleted or moved — the eight settings pages removed on
 * 2026-09-12, an old namespace for DeliveryRequestContext — is still "found",
 * the include fails, and Laravel's error handler turns the warning into a 500.
 * Even class_exists() on the old name dies. Nothing on a host without a
 * Composer binary can regenerate the map.
 *
 * The fix is in the loader, not on disk. findFile() uses isset() on the map,
 * and isset() is true for an empty string; loadClass() then does
 * `if ($file = $this->findFile($class))`, so an entry set to '' is skipped
 * without an include and the lookup falls through to the next autoloader —
 * bootstrap/magna-autoload.php, which finds no file and declines. class_exists()
 * then answers false, which is the truth. addClassMap() merges with the later
 * value winning, exactly as PluginAutoloader already relies on for plugin
 * prefixes.
 *
 * Only `Magna\` and `App\` entries are considered: those are the prefixes a
 * core release owns and rewrites. The scan walks the whole map once per core
 * version (and per classmap file, should Composer ever run) and caches the
 * result under storage/framework/cache, so a request pays one is_file() per
 * core entry only after an update, never on every boot.
 */
final class StaleClassMap
{
    /** @var list<string> */
    private const PREFIXES = ['Magna\\', 'App\\'];

    /** @var list<string>|null */
    private ?array $stale = null;

    /**
     * @param  string  $cachePath  where the scan result is remembered; must be writable, or the scan simply repeats
     * @param  string  $classMapPath  vendor/composer/autoload_classmap.php, whose mtime invalidates the cache
     * @param  string  $version  the running core version, which also invalidates it
     */
    public function __construct(
        private readonly string $cachePath,
        private readonly string $classMapPath,
        private readonly string $version,
    ) {}

    public static function isCorePrefixed(string $class): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Point every stale core entry at nothing, for this process.
     *
     * @return list<string> the classes disarmed
     */
    public function neutralise(?ClassLoader $loader): array
    {
        if ($loader === null) {
            return [];
        }

        $stale = $this->stale($loader);

        if ($stale !== []) {
            $loader->addClassMap(array_fill_keys($stale, ''));
        }

        return $stale;
    }

    /**
     * Record the answer ahead of a boot, from what a release manifest says it
     * removed. The updater calls this for the version it has just laid down,
     * so the first request on the new code neutralises without scanning. A
     * name that is not actually in this site's map costs one empty entry.
     *
     * @param  list<string>  $stale
     */
    public function prime(array $stale): void
    {
        $stale = array_values(array_unique(array_filter($stale, self::isCorePrefixed(...))));
        sort($stale);

        $this->writeCache($stale);
        $this->stale = $stale;
    }

    /**
     * Core classmap entries whose file is gone. From the cache when it matches
     * this version and classmap; from a fresh scan of $loader otherwise. With
     * no loader and no valid cache there is nothing to say.
     *
     * @return list<string>
     */
    public function stale(?ClassLoader $loader = null): array
    {
        if ($this->stale !== null) {
            return $this->stale;
        }

        $cached = $this->readCache();

        if ($cached !== null) {
            return $this->stale = $cached;
        }

        if ($loader === null) {
            return [];
        }

        $stale = $this->scan($loader);
        $this->writeCache($stale);

        return $this->stale = $stale;
    }

    /** @return list<string> */
    private function scan(ClassLoader $loader): array
    {
        $stale = [];

        foreach ($loader->getClassMap() as $class => $file) {
            if (! self::isCorePrefixed($class)) {
                continue;
            }

            if ($file === '' || ! is_file($file)) {
                $stale[] = $class;
            }
        }

        sort($stale);

        return $stale;
    }

    private function classMapMtime(): int
    {
        $mtime = @filemtime($this->classMapPath);

        return $mtime === false ? 0 : $mtime;
    }

    /**
     * JSON, not a PHP file: the cache is data read at every boot, and data
     * that is never executed cannot become a way in should the cache directory
     * be writable by more than PHP.
     *
     * @return list<string>|null
     */
    private function readCache(): ?array
    {
        if (! is_file($this->cachePath)) {
            return null;
        }

        $raw = @file_get_contents($this->cachePath);
        $cached = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($cached)
            || ($cached['version'] ?? null) !== $this->version
            || ($cached['classmap_mtime'] ?? null) !== $this->classMapMtime()
            || ! is_array($cached['stale'] ?? null)) {
            return null;
        }

        return array_values(array_filter($cached['stale'], 'is_string'));
    }

    /**
     * Written to a sibling and renamed into place so a concurrent boot never
     * reads a half-written file. Failure is swallowed: an unwritable cache
     * directory costs a rescan per boot, which is a slow site, not a broken one.
     *
     * @param  list<string>  $stale
     */
    private function writeCache(array $stale): void
    {
        $payload = json_encode([
            'version' => $this->version,
            'classmap_mtime' => $this->classMapMtime(),
            'stale' => $stale,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (is_string($payload)) {
            AtomicFile::write($this->cachePath, $payload);
        }
    }
}
