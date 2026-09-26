<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Magna\MagnaServiceProvider;
use Magna\Updater\CoreSnapshot;
use Magna\Updater\CoreUpdater;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The first tests to run the apply past its guards. Every earlier test
 * stopped at the checksum gate, because the overlay was welded to
 * base_path(): running it would have rewritten the tree the suite executes
 * from. UpdatePaths is rebound at a fixture install, so download, snapshot,
 * overlay, rollback and cleanup all happen in a temp directory.
 */
function hardeningInstall(): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-hardening-'.bin2hex(random_bytes(6));

    foreach (['src/Magna', 'app', 'bootstrap', 'routes', 'database/migrations', 'config/defaults', 'public/build', 'public/fonts'] as $relative) {
        mkdir($base.'/'.$relative, 0777, true);
        file_put_contents($base.'/'.$relative.'/Installed.php', "<?php // installed\n");
    }
    mkdir($base.'/storage/app', 0777, true);

    $paths = new UpdatePaths($base, $base.'/storage');
    app()->instance(UpdatePaths::class, $paths);

    // The fixtures carry no signature; that gate is covered in ReleaseArchiveTest.
    config(['magna.updater.allow_unsigned_checksum' => true]);

    return $paths;
}

function removeHardeningInstall(UpdatePaths $paths): void
{
    (new Filesystem)->remove($paths->basePath);
}

/**
 * A release archive with one new file per core-owned path and the marker
 * `Installed.php` absent from src/Magna, so a mirror with delete removes it.
 *
 * @return array{bytes: string, sha256: string}
 */
function hardeningArchive(): array
{
    $zipPath = tempnam(sys_get_temp_dir(), 'magna-hardening-zip-');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);

    foreach (['src/Magna', 'app', 'bootstrap', 'routes', 'database/migrations', 'config/defaults', 'public/build', 'public/fonts'] as $relative) {
        $zip->addFromString($relative.'/Delivered.php', "<?php // delivered by the release\n");
    }
    $zip->addFromString('app/Installed.php', "<?php // still here\n");
    $zip->close();

    $bytes = (string) file_get_contents($zipPath);
    @unlink($zipPath);

    return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes)];
}

it('applies a release end to end against a fixture install', function (): void {
    $paths = hardeningInstall();
    $archive = hardeningArchive();
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Completed)
            ->and(CoreUpdater::progress()['message'])->toBe('Updated to v99.0.0.');

        // Delivered, and the file the release no longer ships is gone.
        expect(is_file($paths->base('src/Magna/Delivered.php')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeFalse()
            ->and(is_file($paths->base('app/Installed.php')))->toBeTrue()
            ->and(is_file($paths->base('config/defaults/Delivered.php')))->toBeTrue();

        // The snapshot stays (it is the newest), the temp files do not.
        expect(glob($paths->backupsDir().'/*'))->toHaveCount(1)
            ->and(glob($paths->tmpDir().'/*'))->toBe([]);

        // The whole run is on record, and the site is back up.
        $logs = glob($paths->runsDir().'/*/log.txt') ?: [];
        expect($logs)->toHaveCount(1);
        $log = (string) file_get_contents($logs[0]);
        expect($log)->toContain('Applying v99.0.0 over v'.MagnaServiceProvider::VERSION)
            ->and($log)->toContain('Maintenance mode on')
            ->and($log)->toContain('Maintenance mode off')
            ->and($log)->toContain('Updated to v99.0.0.');
        expect(app()->maintenanceMode()->active())->toBeFalse();

        // Workers holding the previous release's classes are told to exit.
        expect(Cache::get('illuminate:queue:restart'))->not->toBeNull();
    } finally {
        removeHardeningInstall($paths);
    }
});

it('removes the downloaded archive when its checksum does not match', function (): void {
    $paths = hardeningInstall();
    Http::fake(['github.com/*' => Http::response('not the bytes that were published')]);

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', str_repeat('a', 64));

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('checksum does not match');

        // Nothing overlaid, temp gone, and no snapshot was taken: the archive
        // is verified before anything is copied aside.
        expect(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(glob($paths->tmpDir().'/*'))->toBe([])
            ->and(glob($paths->backupsDir().'/*') ?: [])->toBe([]);
    } finally {
        removeHardeningInstall($paths);
    }
});

/*
 * A failure between the archive being verified and the overlay starting used
 * to escape apply() as a stack trace, with the lock released and nothing left
 * to lift maintenance mode. Now it is a message, and the site is up.
 */
it('reports a failure before any file was changed instead of throwing', function (): void {
    $paths = hardeningInstall();
    $archive = hardeningArchive();
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    $snapshot = Mockery::mock(CoreSnapshot::class);
    $snapshot->shouldReceive('create')->once()->andThrow(new RuntimeException('backup disk vanished'));
    app()->instance(CoreSnapshot::class, $snapshot);

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('before any files were changed')
            ->and(CoreUpdater::progress()['message'])->toContain('backup disk vanished')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(app()->maintenanceMode()->active())->toBeFalse()
            ->and(glob($paths->tmpDir().'/*'))->toBe([]);
    } finally {
        removeHardeningInstall($paths);
    }
});

it('refuses a release that is not newer than the installed version before touching anything', function (): void {
    $paths = hardeningInstall();
    Http::fake();

    try {
        $state = app(CoreUpdater::class)->apply(MagnaServiceProvider::VERSION, 'https://github.com/magna-cms/magna/archive/v1.zip', str_repeat('a', 64));

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('not newer than the installed');

        Http::assertNothingSent();
        expect(is_dir($paths->backupsDir()))->toBeFalse();
    } finally {
        removeHardeningInstall($paths);
    }
});
