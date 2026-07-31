<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

/**
 * A require-dev package does not exist in a production deployment: the install
 * step there is `composer install --no-dev`. Importing one of its classes from
 * shipped code therefore compiles and tests perfectly on every developer
 * machine and dies with "Target class does not exist" the first time a customer
 * touches the feature.
 *
 * That is not hypothetical. Symfony's Filesystem component was reachable in
 * development only because vimeo/psalm depended on it, while core's updater,
 * theme manager, licence installer and the plugin zip installer all
 * constructor-injected it. The whole admin panel returned "Error while loading
 * page" in production the moment anyone clicked Install on a plugin.
 */
it('never imports a require-dev-only class from shipped code', function (): void {
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);

    expect($lock)->toBeArray();

    /** @var array{packages: array<int, array<string, mixed>>, packages-dev: array<int, array<string, mixed>>} $lock */

    /**
     * @param  array<int, array<string, mixed>>  $packages
     * @return array<string, string>
     */
    $prefixes = static function (array $packages): array {
        $found = [];

        foreach ($packages as $package) {
            $autoload = $package['autoload'] ?? [];
            $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? []) : [];

            if (! is_array($psr4)) {
                continue;
            }

            foreach (array_keys($psr4) as $prefix) {
                if (! is_string($prefix) || $prefix === '') {
                    continue;
                }

                $found[$prefix] = is_string($package['name'] ?? null) ? $package['name'] : 'unknown';
            }
        }

        return $found;
    };

    $production = $prefixes($lock['packages']);

    // Namespaces the root project autoloads itself. laravel/pint ships test
    // fixtures under App\ and Database\Factories\, so without this the guard
    // would flag the project's own classes.
    $ownPrefixes = ['Magna\\', 'App\\', 'Database\\Factories\\', 'Database\\Seeders\\', 'Tests\\'];

    // Local plugins are path repositories listed under require-dev so they load
    // during development; they are deployed as real installed plugins, not
    // pulled from Packagist, so their namespaces are not a dev-only risk.
    $devPackages = array_values(array_filter(
        $lock['packages-dev'],
        static function (array $package): bool {
            $dist = $package['dist'] ?? [];

            return ! is_array($dist) || ($dist['type'] ?? null) !== 'path';
        }
    ));

    $devOnly = [];

    foreach ($prefixes($devPackages) as $prefix => $package) {
        if (isset($production[$prefix]) || in_array($prefix, $ownPrefixes, true)) {
            continue;
        }

        $devOnly[$prefix] = $package;
    }

    // Without this the whole guard could pass vacuously: an autoload format
    // change upstream that emptied $devOnly would read as "no violations".
    expect($devOnly)->not->toBeEmpty();

    $offenders = [];

    foreach ([base_path('src/Magna'), base_path('app')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)/m', $source, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $class) {
                foreach ($devOnly as $prefix => $package) {
                    if (str_starts_with($class, $prefix)) {
                        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                        $offenders[] = $relative.' imports '.$class.' (require-dev: '.$package.')';
                    }
                }
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});

/**
 * The component the production incident was actually about. Keeping an explicit
 * assertion means a future `composer remove` that drops it back into require-dev
 * fails here with an obvious name rather than as a generic guard violation.
 */
it('declares symfony/filesystem as a production dependency', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer)->toBeArray()
        ->and($composer['require'] ?? [])->toHaveKey('symfony/filesystem');
});
