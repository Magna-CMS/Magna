<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature/Install is exempt from RefreshDatabase on purpose: the installer
// migrates its own database connection mid-test, which conflicts with
// RefreshDatabase's transaction wrapping and cached in-memory connections.
// New Feature directories must be added to the RefreshDatabase line below.
pest()->extend(TestCase::class)->in('Feature/Install');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in(
    'Feature/App',
    'Feature/Auth',
    'Feature/Users',
    'Feature/Api',
    'Feature/Settings',
    'Feature/Audit',
    'Feature/Licensing',
);
// Management and Webhook tests declare uses() explicitly at the top of each file
// (same pattern as Feature/Content and Feature/Media) so they are not listed here.
// Feature/Content and Feature/Plugins are NOT in the global list because some
// tests in those directories use PluginTestCase (a different base class).
// They declare uses() explicitly at the top of each file instead.

/**
 * The declared manifest of a first-party dev plugin, located by its package
 * NAME rather than by a path built from it — or null when no plugin in the
 * checkout declares that name.
 *
 * A plugin's package name and its directory are independent: half of the
 * first-party ones already differ (magna-cms/docs lives in plugins-dev/magna/
 * docs, and so do blog, marketplace, message-lite and pages). Guessing the
 * path from the name misses them, and the miss is SILENT — a helper keyed on
 * the guess skips (or reads nothing) and reports a green run that exercised
 * nothing. Eight Magna Docs tests had been skipping that way before this was
 * noticed. The manifest is the authority on a plugin's identity — the same
 * field PluginDiscovery keys on — so ask it.
 *
 * @return array<string, mixed>|null
 */
function devPluginManifest(string $package): ?array
{
    foreach (glob(base_path('plugins-dev/*/*/magna.json')) ?: [] as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (is_array($manifest) && ($manifest['name'] ?? null) === $package) {
            /** @var array<string, mixed> $manifest */
            return $manifest;
        }
    }

    return null;
}

/**
 * Skip a test that exercises a first-party plugin against the real plugin.
 *
 * Plugins live in their own repositories and are wired into a development
 * checkout through plugins-dev/. A clone of core alone therefore cannot run
 * these — and must not report a failure for something it was never shipped.
 * Where a plugin is only a convenient stand-in for "some installed plugin",
 * prefer a fixture plugin over this helper.
 */
function skipWithoutDevPlugin(string $package): void
{
    if (devPluginManifest($package) === null) {
        test()->markTestSkipped("The {$package} plugin is not part of this checkout.");
    }
}

/**
 * The version a first-party plugin currently declares.
 *
 * Never hardcode a plugin's version in a core test. Plugins version
 * independently in their own repositories, so a pinned literal turns every
 * routine plugin release into a red core suite — which is exactly how
 * PluginInstallerTest started failing when Magna Docs went 1.0.0 -> 1.1.0.
 * Call this after skipWithoutDevPlugin() for the same package.
 */
function devPluginVersion(string $package): string
{
    $version = devPluginManifest($package)['version'] ?? null;

    return is_string($version) ? $version : '0.0.0';
}
