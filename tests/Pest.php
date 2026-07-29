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
    if (! is_dir(base_path('plugins-dev/'.$package))) {
        test()->markTestSkipped("The {$package} plugin is not part of this checkout.");
    }
}
