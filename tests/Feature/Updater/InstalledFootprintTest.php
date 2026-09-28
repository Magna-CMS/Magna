<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3).'/bin/support/release-manifest.php';

use Magna\Install\Installer;
use Magna\MagnaServiceProvider;
use Magna\Updater\CoreUpdater;
use Magna\Updater\Engine\PathGuard;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\Manifest\ReleaseManifest;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The migrations table for the file layer: what was delivered, by what. A
 * site that updated 1.4.2 → 1.4.3 reported itself up to date while missing
 * the directory that release existed to deliver, because nothing recorded
 * what the overlay had done.
 */
function footprintPaths(): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-footprint-'.bin2hex(random_bytes(6));
    mkdir($base.'/storage/app', 0777, true);

    return new UpdatePaths($base, $base.'/storage');
}

it('records a delivery and reads it back, keeping the one before', function (): void {
    $paths = footprintPaths();
    $footprint = new InstalledFootprint($paths);

    try {
        expect($footprint->read())->toBeNull();

        expect($footprint->record('1.4.4', 'update', 'run-1', null, ['src/Magna']))->toBeTrue();

        $first = $footprint->read();
        expect($first['schema'] ?? null)->toBe(InstalledFootprint::SCHEMA)
            ->and($first['version'] ?? null)->toBe('1.4.4')
            ->and($first['installed_via'] ?? null)->toBe('update')
            ->and($first['run_id'] ?? null)->toBe('run-1')
            ->and($first['paths'] ?? null)->toBe(['src/Magna'])
            ->and(array_key_exists('previous', $first))->toBeTrue()
            ->and($first['previous'])->toBeNull()
            ->and($first['layout'] ?? null)->toBe('in-place');

        $footprint->record('v1.4.5', 'update', 'run-2', null, ['src/Magna', 'app']);

        $second = $footprint->read();
        expect($second['version'] ?? null)->toBe('1.4.5')
            ->and($second['previous']['version'] ?? null)->toBe('1.4.4')
            ->and($second['previous']['run_id'] ?? null)->toBe('run-1');
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('is recorded by a fresh install from the manifest extracted with the archive', function (): void {
    $paths = footprintPaths();

    $manifest = release_manifest(
        MagnaServiceProvider::VERSION,
        CoreUpdater::coreOwnedPaths(),
        [],
        'v1.2.0',
        1,
        ['commit' => null, 'tag' => null],
        gmdate('c'),
    );
    file_put_contents($paths->base(ReleaseManifest::FILENAME), (string) json_encode($manifest));

    // A core-only archive extracts every core-owned path but the optional
    // bundled SDK source, which only a hub build stages. The footprint must
    // record what actually landed, not every path the manifest names — else it
    // enters bundled/magna-cms/plugin-sdk and the check reports it removed.
    $present = array_values(array_diff(CoreUpdater::coreOwnedPaths(), [CoreUpdater::SDK_SOURCE_PATH]));
    foreach ($present as $relative) {
        mkdir($paths->base($relative), 0777, true);
    }

    // Keep the installer's own lock file out of the real storage directory.
    config(['magna.install.lock_path' => $paths->storage('app/magna-installed.json')]);

    try {
        Installer::markInstalled($paths);

        $recorded = (new InstalledFootprint($paths))->read();

        expect($recorded['installed_via'] ?? null)->toBe('fresh')
            ->and($recorded['version'] ?? null)->toBe(MagnaServiceProvider::VERSION)
            ->and($recorded['paths'] ?? null)->toBe($present)
            ->and($recorded['paths'] ?? [])->not->toContain(CoreUpdater::SDK_SOURCE_PATH)
            ->and($recorded['optional_paths'] ?? null)->toContain(CoreUpdater::SDK_SOURCE_PATH)
            ->and($recorded['manifest_sha256'] ?? null)->toBe(ReleaseManifest::fromExtractedArchive($paths->basePath, new PathGuard)?->sha256);
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('records a fresh install from a checkout that carries no manifest', function (): void {
    $paths = footprintPaths();
    config(['magna.install.lock_path' => $paths->storage('app/magna-installed.json')]);

    try {
        Installer::markInstalled($paths);

        $recorded = (new InstalledFootprint($paths))->read();

        expect($recorded['installed_via'] ?? null)->toBe('fresh')
            ->and($recorded['paths'] ?? null)->toBe([])
            ->and(array_key_exists('manifest_sha256', $recorded))->toBeTrue()
            ->and($recorded['manifest_sha256'])->toBeNull();
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});
