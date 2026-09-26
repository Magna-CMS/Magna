<?php

declare(strict_types=1);

namespace Magna\Updater\Manifest;

use Magna\Updater\Engine\PathGuard;

/**
 * What a magna-release.json must look like — one implementation, two readers.
 *
 * bin/build-release.php runs these rules over the manifest it writes, so an
 * archive that would be refused on every site is refused on the build machine
 * instead. The updater runs the same rules over the manifest it extracts. Pure
 * static functions with no framework dependency, so the builder can call them
 * through nothing but Composer's autoloader.
 *
 * Every rule bounds something an attacker who could not forge the signature
 * has no way to abuse anyway; the bounds exist so a malformed build cannot
 * make the updater walk a million paths or read a class name the size of a
 * file.
 */
final class ManifestRules
{
    public const PRODUCT = 'magna-cms';

    /** @var list<int> */
    public const SUPPORTED_SCHEMAS = [1];

    public const MAX_PATHS = 64;

    public const MAX_REMOVED_CLASSES = 5000;

    public const MAX_CHECKS = 100;

    /** Where a release engine may live inside the archive. */
    public const ENGINE_DIRECTORY = 'bootstrap/update';

    /**
     * What a release may ask of the site's vendor/: `keep` leaves it alone
     * beyond the SDK, `replace` lets the engine decide per site — replace it
     * whole when the site holds nothing the release does not know, run
     * Composer when it does and Composer is there, refuse otherwise.
     *
     * @var list<string>
     */
    public const VENDOR_STRATEGIES = ['keep', 'replace'];

    /**
     * Every reason the manifest cannot be used, or [] when it can.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function validate(array $data, PathGuard $guard): array
    {
        $problems = [];

        $schema = $data['schema'] ?? null;
        if (! is_int($schema) || ! in_array($schema, self::SUPPORTED_SCHEMAS, true)) {
            $problems[] = 'Manifest schema '.(is_scalar($schema) ? (string) $schema : 'missing').' is not one this updater reads (supports: '.implode(', ', self::SUPPORTED_SCHEMAS).'). The release needs a newer updater than this site runs.';
        }

        if (($data['product'] ?? null) !== self::PRODUCT) {
            $problems[] = 'The manifest is not for '.self::PRODUCT.'.';
        }

        $version = $data['version'] ?? null;
        if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            $problems[] = 'The manifest version is not a clean x.y.z version.';
        }

        $problems = [...$problems, ...self::validateRequires($data['requires'] ?? null)];
        $problems = [...$problems, ...self::validatePaths($data['paths'] ?? null, $guard)];
        $problems = [...$problems, ...self::validateEngine($data['engine'] ?? null)];
        $problems = [...$problems, ...self::validateClassList($data['removed_classes'] ?? null, 'removed_classes', self::MAX_REMOVED_CLASSES)];

        $checks = $data['checks'] ?? [];
        if (! is_array($checks)) {
            $problems[] = 'checks must be an object.';
        } else {
            $problems = [...$problems, ...self::validatePathList($checks['files'] ?? null, 'checks.files', $guard, self::MAX_CHECKS)];
            $problems = [...$problems, ...self::validateClassList($checks['classes'] ?? null, 'checks.classes', self::MAX_CHECKS)];
        }

        $size = $data['size'] ?? [];
        if (! is_array($size) || (isset($size['uncompressed_bytes']) && (! is_int($size['uncompressed_bytes']) || $size['uncompressed_bytes'] < 0))) {
            $problems[] = 'size.uncompressed_bytes must be a non-negative integer.';
        }

        $vendor = $data['vendor'] ?? [];
        if (! is_array($vendor) || (isset($vendor['strategy']) && ! in_array($vendor['strategy'], self::VENDOR_STRATEGIES, true))) {
            $problems[] = 'vendor.strategy must be one of '.implode(', ', self::VENDOR_STRATEGIES).'.';
        }

        return $problems;
    }

    /** @return list<string> */
    private static function validateRequires(mixed $requires): array
    {
        if ($requires === null) {
            return [];
        }

        if (! is_array($requires)) {
            return ['requires must be an object.'];
        }

        $problems = [];

        if (isset($requires['php']) && (! is_string($requires['php']) || trim($requires['php']) === '')) {
            $problems[] = 'requires.php must be a version constraint.';
        }

        if (isset($requires['extensions'])) {
            if (! is_array($requires['extensions']) || ! array_is_list($requires['extensions'])) {
                $problems[] = 'requires.extensions must be a list of extension names.';
            } else {
                foreach ($requires['extensions'] as $extension) {
                    if (! is_string($extension) || preg_match('/^[a-z0-9_]{1,40}$/i', $extension) !== 1) {
                        $problems[] = 'requires.extensions holds something that is not an extension name.';
                        break;
                    }
                }
            }
        }

        if (isset($requires['min_upgrade_from']) && (! is_string($requires['min_upgrade_from']) || preg_match('/^\d+\.\d+\.\d+/', $requires['min_upgrade_from']) !== 1)) {
            $problems[] = 'requires.min_upgrade_from must be a version.';
        }

        return $problems;
    }

    /** @return list<string> */
    private static function validatePaths(mixed $paths, PathGuard $guard): array
    {
        if (! is_array($paths)) {
            return ['paths must be an object with a core_owned list.'];
        }

        if (! isset($paths['core_owned'])) {
            return ['paths.core_owned is missing.'];
        }

        return [
            ...self::validatePathList($paths['core_owned'], 'paths.core_owned', $guard, self::MAX_PATHS),
            ...self::validatePathList($paths['optional'] ?? null, 'paths.optional', $guard, self::MAX_PATHS),
            ...self::validatePathList($paths['removed'] ?? null, 'paths.removed', $guard, self::MAX_PATHS),
        ];
    }

    /** @return list<string> */
    private static function validatePathList(mixed $list, string $field, PathGuard $guard, int $max): array
    {
        if ($list === null) {
            return [];
        }

        if (! is_array($list) || ! array_is_list($list)) {
            return ["{$field} must be a list of paths."];
        }

        if (count($list) > $max) {
            return ["{$field} names more than {$max} paths."];
        }

        $problems = [];

        foreach ($list as $path) {
            if (! is_string($path)) {
                $problems[] = "{$field} holds something that is not a path.";

                continue;
            }

            $reason = $guard->reject($path);

            if ($reason !== null) {
                $problems[] = "{$field}: {$reason}";
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function validateClassList(mixed $list, string $field, int $max): array
    {
        if ($list === null) {
            return [];
        }

        if (! is_array($list) || ! array_is_list($list)) {
            return ["{$field} must be a list of class names."];
        }

        if (count($list) > $max) {
            return ["{$field} names more than {$max} classes."];
        }

        foreach ($list as $class) {
            if (! is_string($class) || preg_match('/^(Magna|App)\\\\[A-Za-z0-9_\\\\]{1,200}$/', $class) !== 1) {
                return ["{$field} holds something that is not a Magna\\ or App\\ class name."];
            }
        }

        return [];
    }

    /** @return list<string> */
    private static function validateEngine(mixed $engine): array
    {
        if ($engine === null) {
            return [];
        }

        if (! is_array($engine)) {
            return ['engine must be an object.'];
        }

        $problems = [];

        if (! is_int($engine['api'] ?? null) || $engine['api'] < 1) {
            $problems[] = 'engine.api must be a positive integer.';
        }

        $path = $engine['path'] ?? null;
        $normalized = is_string($path) ? PathGuard::normalize($path) : null;
        if ($normalized === null || ! str_starts_with($normalized, self::ENGINE_DIRECTORY.'/') || ! str_ends_with($normalized, '.php')) {
            $problems[] = 'engine.path must name a PHP file under '.self::ENGINE_DIRECTORY.'/.';
        }

        if (! is_string($engine['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $engine['sha256']) !== 1) {
            $problems[] = 'engine.sha256 must be a lowercase hex SHA-256.';
        }

        return $problems;
    }
}
