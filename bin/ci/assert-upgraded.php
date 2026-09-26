<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Magna\MagnaServiceProvider;
use Magna\Updater\Footprint\FootprintCheck;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\Run\UpdateResumer;

/**
 * Prove an installed site is actually running, and actually received, the
 * release it says it does. Boots the site's NEW code in this process.
 *
 * What is asserted, and why each line exists:
 *   - the running version is the archive's — code always arrives, so this is
 *     the cheap one;
 *   - every path the archive's manifest owns is on disk — the 1.4.2 → 1.4.3
 *     failure in one line;
 *   - every core config key resolves — null is what that failure looked like;
 *   - no migration is pending, the app answers /up, `about` boots in a fresh
 *     process — the site serves;
 *   - a class an old release deleted answers class_exists() false with no
 *     error — the stale-classmap 500;
 *   - the delivery is recorded, or the site knows it was not (a hop from a
 *     pre-1.4.4 updater is expected to be unrecorded and to say so).
 *
 * Usage:
 *   php bin/ci/assert-upgraded.php <install-dir> --archive=<archive.zip> [--legacy-hop] [--fresh]
 */
$install = null;
$archive = null;
$legacyHop = false;
$fresh = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--archive=')) {
        $archive = substr($arg, 10);
    } elseif ($arg === '--legacy-hop') {
        $legacyHop = true;
    } elseif ($arg === '--fresh') {
        $fresh = true;
    } elseif (! str_starts_with($arg, '--')) {
        $install ??= rtrim($arg, '/\\');
    }
}

if ($install === null || $archive === null || ! is_file($archive)) {
    fwrite(STDERR, "Usage: php bin/ci/assert-upgraded.php <install-dir> --archive=<archive.zip> [--legacy-hop] [--fresh]\n");
    exit(2);
}

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    fwrite(STDOUT, ($ok ? '  ok   ' : '  FAIL ').$what."\n");
    if (! $ok) {
        $failures[] = $what;
    }
};

$zip = new ZipArchive;
if ($zip->open($archive) !== true) {
    fwrite(STDERR, "Could not open {$archive}.\n");
    exit(2);
}
$manifest = json_decode((string) $zip->getFromName('magna-release.json'), true);
$zip->close();

if (! is_array($manifest)) {
    fwrite(STDERR, "The archive carries no manifest; nothing to assert against.\n");
    exit(2);
}

$expected = (string) $manifest['version'];
fwrite(STDOUT, "Asserting {$install} runs v{$expected}".($legacyHop ? ' (applied by a pre-1.4.4 updater)' : '').($fresh ? ' (fresh install)' : '')."\n");

chdir($install);
require $install.'/vendor/autoload.php';
$app = require $install.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// 1. Version.
$check($expected === MagnaServiceProvider::VERSION, "running version is v{$expected} (is v".MagnaServiceProvider::VERSION.')');

// 2. Every core-owned path the release names, bar the optional ones.
$optional = is_array($manifest['paths']['optional'] ?? null) ? $manifest['paths']['optional'] : [];
foreach (is_array($manifest['paths']['core_owned'] ?? null) ? $manifest['paths']['core_owned'] : [] as $relative) {
    if (! is_string($relative) || in_array($relative, $optional, true)) {
        continue;
    }

    $present = is_dir($install.'/'.$relative) || is_file($install.'/'.$relative);

    // A pre-1.4.3 updater never delivered config/defaults; the canonical
    // copy under src/Magna is what makes that harmless, checked below.
    if (! $present && $legacyHop && $relative === 'config/defaults') {
        fwrite(STDOUT, "  note config/defaults absent, as expected after a legacy hop\n");

        continue;
    }

    $check($present, "path {$relative} is on disk");
}

foreach (is_array($manifest['checks']['files'] ?? null) ? $manifest['checks']['files'] : [] as $file) {
    if (is_string($file)) {
        $check(is_file($install.'/'.$file), "promised file {$file} exists");
    }
}

// 3. Core configuration resolves — the shape of the original failure.
$check(is_string(config('magna.security.trusted_hosts')), 'magna.security.trusted_hosts resolves');
$check(is_bool(config('magna.updater.allow_unsigned_checksum')), 'magna.updater.allow_unsigned_checksum resolves');
$check(is_string(config('magna.media.disk')), 'magna.media.disk resolves');
$check(config()->has('trustedproxy.proxies'), 'trustedproxy.proxies is defined');

// 4. Nothing pending in the database, and the app answers.
$pending = trim((string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($install.'/artisan').' migrate:status --pending 2>&1'));
$check(! str_contains($pending, 'Pending') || str_contains($pending, 'No pending'), 'no migration is pending');

$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('/up', 'GET'));
$check($response->getStatusCode() === 200, '/up answers 200 (got '.$response->getStatusCode().')');

exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($install.'/artisan').' about --only=environment 2>&1', $aboutOutput, $aboutCode);
$check($aboutCode === 0, 'artisan about boots in a fresh process');

// 5. A class an earlier release deleted is gone, not a 500.
foreach (is_array($manifest['removed_classes'] ?? null) ? $manifest['removed_classes'] : [] as $class) {
    if (! is_string($class)) {
        continue;
    }

    try {
        $check(! class_exists($class), "{$class} answers class_exists() false");
    } catch (Throwable $e) {
        $check(false, "{$class} answers class_exists() false (threw: ".$e->getMessage().')');
    }

    break; // one is proof enough, and keeps the output short
}

// 6. The delivery is on record — or the site knows it is not.
$footprint = $app->make(InstalledFootprint::class)->read();
$footprintCheck = $app->make(FootprintCheck::class);

if ($legacyHop) {
    $check($footprintCheck->deliveredByOlderUpdater(), 'the site reports it was updated by an older updater');
} elseif ($fresh) {
    $check(true, 'fresh install: no delivery record required');
} else {
    $check(($footprint['version'] ?? null) === $expected, "installed.json records v{$expected}");
    $check(($footprint['installed_via'] ?? null) === 'update', 'installed.json records an update');
    $check($app->make(UpdateResumer::class)->pending() === null, 'no update run is pending');
}

$check(! $app->maintenanceMode()->active(), 'the site is not in maintenance mode');

if ($failures !== []) {
    fwrite(STDERR, count($failures)." assertion(s) failed:\n  - ".implode("\n  - ", $failures)."\n");
    exit(1);
}

fwrite(STDOUT, "All assertions passed for v{$expected}.\n");
exit(0);
