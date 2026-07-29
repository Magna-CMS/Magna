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
