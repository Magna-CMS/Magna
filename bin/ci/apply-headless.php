<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Magna\Updater\CoreUpdater;

/**
 * Apply a release archive to an installed site through THAT site's own
 * updater — the installed release's code, whatever it knows.
 *
 * This is the hop the upgrade matrix exists to prove: the previous release
 * (1.4.3, 1.3.x, 1.2.0) performing the update to the current build, exactly
 * as a customer's site would. The site's application is booted in this
 * process from its own vendor/ and bootstrap/, the download is faked with the
 * archive's bytes, and apply() is called with whatever arguments that era's
 * signature takes. A hand-off (1.4.4+) is then finished in a fresh process
 * through `magna:core:resume`, which is the new code by construction.
 *
 * Usage:
 *   php bin/ci/apply-headless.php <install-dir> <archive.zip>
 */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/ci/apply-headless.php <install-dir> <archive.zip>\n");
    exit(2);
}

$install = rtrim($argv[1], '/\\');
$archive = $argv[2];

if (! is_file($install.'/bootstrap/app.php') || ! is_file($install.'/vendor/autoload.php')) {
    fwrite(STDERR, "{$install} is not an installed Magna site.\n");
    exit(2);
}

if (! is_file($archive)) {
    fwrite(STDERR, "No archive at {$archive}.\n");
    exit(2);
}

$bytes = (string) file_get_contents($archive);
$sha256 = hash('sha256', $bytes);
$target = archive_version($archive);

fwrite(STDOUT, "Applying v{$target} ({$sha256}) over ".site_version($install)." at {$install}\n");

// Boot the SITE's application, not this repository's.
chdir($install);
require $install.'/vendor/autoload.php';
$app = require $install.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Http::fake([
    'github.com/*' => Http::response($bytes),
    'objects.githubusercontent.com/*' => Http::response($bytes),
]);

$updater = $app->make(CoreUpdater::class);
$signature = new ReflectionMethod($updater, 'apply');
$parameters = $signature->getNumberOfParameters();
$zipUrl = 'https://github.com/magna-cms/magna/releases/download/v'.$target.'/magna-cms-v'.$target.'.zip';

// Every era's apply() starts (target, zipUrl); from v1.3.x it takes the
// checksum third; force and signature stay at their defaults.
$arguments = $parameters >= 3 ? [$target, $zipUrl, $sha256] : [$target, $zipUrl];

$state = $updater->apply(...$arguments);
$progress = CoreUpdater::progress();

fwrite(STDOUT, 'apply() returned '.(is_object($state) && property_exists($state, 'value') ? $state->value : var_export($state, true))."\n");
fwrite(STDOUT, 'progress: '.($progress['message'] ?? '')."\n");

$stateValue = is_object($state) && property_exists($state, 'value') ? $state->value : (string) $state;

if ($stateValue === 'failed') {
    fwrite(STDERR, "The site's updater refused or failed the update.\n");
    exit(1);
}

if ($stateValue === 'switched') {
    // The old side has stepped back; only a process on the new code may finish.
    fwrite(STDOUT, "Switched; finishing under the new code in a fresh process…\n");

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($install.'/artisan').' magna:core:resume', $code);

        if ($code === 0) {
            break;
        }

        // 2 = stale code (should not happen in a fresh process), 1 = failed.
        if ($code === 1) {
            fwrite(STDERR, "The finalize failed.\n");
            exit(1);
        }

        sleep(2);
    }

    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($install.'/artisan').' magna:core:status', $code);
}

fwrite(STDOUT, 'Site now reports '.site_version($install)."\n");
exit(0);

function archive_version(string $archive): string
{
    $zip = new ZipArchive;

    if ($zip->open($archive) === true) {
        $manifest = json_decode((string) $zip->getFromName('magna-release.json'), true);
        $zip->close();

        if (is_array($manifest) && is_string($manifest['version'] ?? null)) {
            return $manifest['version'];
        }
    }

    return preg_match('/magna-cms-v([\d.]+)\.zip$/', basename($archive), $m) === 1 ? $m[1] : 'unknown';
}

function site_version(string $install): string
{
    $source = (string) @file_get_contents($install.'/src/Magna/MagnaServiceProvider.php');

    return preg_match('/const\s+VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $m) === 1 ? 'v'.$m[1] : 'unknown';
}
