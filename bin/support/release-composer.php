<?php

declare(strict_types=1);

/**
 * Pure composer.json transformations used by bin/build-release.php.
 *
 * Kept side-effect free and in their own file so tests can exercise them
 * without running a five-minute build. Every rule here was a shipped bug at
 * some point:
 *
 *   - a hub archive that stopped claiming its bundled plugins, so the
 *     `composer require` the Marketplace runs for a plugin install pruned
 *     Marketplace itself out of vendor/;
 *   - path repositories left pointing at absolute build-machine directories,
 *     which no Composer command on the target could resolve;
 *   - a shipped require-dev section, so that same install dragged the whole
 *     dev toolchain onto production.
 *
 * @see tests/Unit/Release/ReleaseComposerTest.php
 */

/**
 * Remove packages the profile does not ship, and promote the ones it does from
 * require-dev (where a working copy wires them) into require (a release
 * installs --no-dev).
 *
 * @param  array<string, mixed>  $composer
 * @param  list<string>  $strip
 * @param  list<string>  $bundled
 * @return array<string, mixed>
 */
function release_apply_plugin_profile(array $composer, array $strip, array $bundled): array
{
    foreach ($strip as $package) {
        unset($composer['require'][$package], $composer['require-dev'][$package]);
    }

    foreach ($bundled as $package) {
        if (isset($composer['require-dev'][$package])) {
            $composer['require'][$package] = $composer['require-dev'][$package];
            unset($composer['require-dev'][$package]);
        }
    }

    return $composer;
}

/**
 * Drop the `type: path` repositories whose package is not shipped. Anything
 * that is not a path repository (Packagist, VCS) is left alone.
 *
 * @param  array<string, mixed>  $composer
 * @param  list<string>  $strip
 * @param  callable(string): ?string  $resolvePackageName  url -> package name
 * @return array<string, mixed>
 */
function release_filter_path_repositories(array $composer, array $strip, callable $resolvePackageName): array
{
    $composer['repositories'] = array_values(array_filter(
        $composer['repositories'] ?? [],
        static function ($repo) use ($strip, $resolvePackageName): bool {
            if (! is_array($repo) || ($repo['type'] ?? null) !== 'path') {
                return true;
            }

            return ! in_array($resolvePackageName((string) ($repo['url'] ?? '')), $strip, true);
        },
    ));

    return $composer;
}

/**
 * Where a bundled path-repository source lives inside the archive.
 *
 * Plugins keep the plugins-dev/{package} layout the app already understands —
 * PluginSource looks there for the writable source behind a vendor mirror. The
 * SDK is not a plugin, so it goes somewhere neutral.
 */
function release_bundle_relative_path(?string $packageName, string $absoluteUrl): string
{
    if ($packageName === null) {
        return 'bundled/unknown/package';
    }

    return str_contains(str_replace('\\', '/', $absoluteUrl), '/plugins-dev/')
        ? 'plugins-dev/'.$packageName
        : 'bundled/'.$packageName;
}

/**
 * A release ships a --no-dev vendor/, so nothing in require-dev is present or
 * wanted — and leaving the section in place makes the next Composer command on
 * the target resolve the entire dev toolchain.
 *
 * @param  array<string, mixed>  $composer
 * @return array<string, mixed>
 */
function release_drop_require_dev(array $composer): array
{
    unset($composer['require-dev']);

    return $composer;
}
