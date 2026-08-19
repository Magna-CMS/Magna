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
 * Turn a working copy's local path wiring into something the target can
 * resolve on its own: drop every remaining `type: path` repository, and
 * replace the branch constraints that only ever made sense against them.
 *
 * A non-hub release carries no path sources — every package it still requires
 * is public and resolves from Packagist. Rewriting those repositories to
 * absolute build-machine directories instead (what this used to do) shipped
 * composer.json entries like `C:/Users/.../magna-plugin-sdk`, and every
 * Composer command on the target then died with "The `url` supplied for the
 * path (…) repository does not exist" — including the `composer require` the
 * Marketplace runs, so no plugin could be installed on a released site at all.
 *
 * The constraint matters as much as the repository: a working copy requires
 * the SDK as `@dev` because the path source has no tags. With the repository
 * gone that constraint would resolve to the package's dev branch on Packagist,
 * so it is swapped for the stable range the source's own branch alias declares.
 *
 * @param  array<string, mixed>  $composer
 * @param  callable(string): ?string  $resolvePackageName  url -> package name
 * @param  callable(string): ?string  $resolveConstraint  url -> public constraint
 * @return array<string, mixed>
 */
function release_publicise_path_repositories(array $composer, callable $resolvePackageName, callable $resolveConstraint): array
{
    foreach (($composer['repositories'] ?? []) as $repo) {
        if (! is_array($repo) || ($repo['type'] ?? null) !== 'path' || ! isset($repo['url'])) {
            continue;
        }

        $url = (string) $repo['url'];
        $package = $resolvePackageName($url);

        if ($package === null || ! isset($composer['require'][$package])) {
            continue;
        }

        $constraint = (string) $composer['require'][$package];

        // Only branch constraints need replacing. A release that already pins
        // a public version keeps it.
        if ($constraint !== '@dev' && ! str_starts_with($constraint, 'dev-')) {
            continue;
        }

        $composer['require'][$package] = $resolveConstraint($url) ?? '*';
    }

    $composer['repositories'] = array_values(array_filter(
        $composer['repositories'] ?? [],
        static fn ($repo): bool => ! is_array($repo) || ($repo['type'] ?? null) !== 'path',
    ));

    return $composer;
}

/**
 * The public constraint a path-repository package should be required at, read
 * from the source's own metadata: a `version`, else the stable range its
 * dev branch aliases to ("1.x-dev" means ^1.0). Null when the source declares
 * neither and the caller has to fall back.
 *
 * @param  array<string, mixed>  $sourceComposer  the package's own composer.json
 */
function release_public_constraint(array $sourceComposer): ?string
{
    $version = $sourceComposer['version'] ?? null;
    if (is_string($version) && $version !== '') {
        $parts = explode('.', ltrim($version, 'vV'));

        return '^'.$parts[0].'.'.($parts[1] ?? '0');
    }

    $aliases = $sourceComposer['extra']['branch-alias'] ?? null;
    if (! is_array($aliases)) {
        return null;
    }

    foreach ($aliases as $alias) {
        // "1.x-dev" / "2.3.x-dev" — the major (and minor, when pinned) of the
        // stable line that branch becomes.
        if (is_string($alias) && preg_match('/^(\d+)\.(\d+|x)/', $alias, $m) === 1) {
            return '^'.$m[1].'.'.($m[2] === 'x' ? '0' : $m[2]);
        }
    }

    return null;
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
