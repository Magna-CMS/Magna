<?php

declare(strict_types=1);

namespace Magna\Backup;

/**
 * Which of vendor/'s directories a backup may leave behind.
 *
 * vendor/ used to be excluded whole, which is right for the framework and
 * every library — large, and reproducible from composer.lock. It is wrong for
 * the installed plugins, which live at vendor/{vendor}/{package} and are the
 * one thing in there that cannot be reproduced: they are private, not on
 * Packagist, and a zip install has no Composer to fetch them with. A site
 * restored from such a backup came back without its ERP, its DMS, or any other
 * paid plugin — the database still described their tables, and the code that
 * reads them was gone.
 *
 * Kept apart from {@see BackupService} because it is the one decision here
 * worth being able to state and test on its own: it touches no filesystem and
 * no config, so a test can hand it a vendor tree that does not exist.
 */
final class VendorSelection
{
    /**
     * Composer's own directory, which a restored plugin cannot load without.
     *
     * The autoload maps and installed.json live here, and nothing on a server
     * without Composer could regenerate them.
     */
    private const COMPOSER = 'composer';

    /**
     * The vendor directories to exclude, given what is there and what is a plugin.
     *
     * @param  string  $vendorPath  Absolute path to vendor/, forward-slashed.
     * @param  list<string>  $entries  Absolute paths of vendor/'s direct children.
     * @param  list<string>  $pluginPaths  Absolute paths of installed plugins.
     * @return list<string>
     */
    public static function toExclude(string $vendorPath, array $entries, array $pluginPaths): array
    {
        $keep = self::toKeep($vendorPath, $pluginPaths);

        return array_values(array_filter(
            $entries,
            static fn (string $entry): bool => ! in_array(self::normalise($entry), $keep, true),
        ));
    }

    /**
     * The vendor namespaces worth carrying.
     *
     * A plugin at vendor/roya/erp keeps vendor/roya, so both Roya plugins ride
     * along on one entry rather than needing one apiece — and so does anything
     * else that vendor ships later.
     *
     * @param  list<string>  $pluginPaths
     * @return list<string>
     */
    private static function toKeep(string $vendorPath, array $pluginPaths): array
    {
        $vendor = rtrim(self::normalise($vendorPath), '/');
        $keep = [$vendor.'/'.self::COMPOSER];

        foreach ($pluginPaths as $path) {
            $path = self::normalise($path);

            // A plugin in plugins-dev/ is already swept by base_path(); only
            // the ones actually under vendor/ have anything to say here.
            if (! str_starts_with($path, $vendor.'/')) {
                continue;
            }

            $namespace = explode('/', substr($path, strlen($vendor) + 1))[0];

            if ($namespace !== '') {
                $keep[] = $vendor.'/'.$namespace;
            }
        }

        return array_values(array_unique($keep));
    }

    private static function normalise(string $path): string
    {
        return rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/');
    }
}
