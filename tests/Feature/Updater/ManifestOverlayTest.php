<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3).'/bin/support/release-manifest.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Magna\Updater\CoreUpdater;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\Manifest\ReleaseManifest;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A release that carries a manifest decides its own paths. This is the fix
 * for the 1.4.2 → 1.4.3 hop: the updater used to copy what ITS list named,
 * so a release could never deliver a directory it introduced.
 */
const MANIFEST_TEST_PATHS = ['config/defaults', 'src/Magna', 'app', 'bootstrap', 'routes', 'database/migrations', 'public/build', 'public/fonts'];

function manifestInstall(): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-manifest-overlay-'.bin2hex(random_bytes(6));

    foreach (MANIFEST_TEST_PATHS as $relative) {
        mkdir($base.'/'.$relative, 0777, true);
        file_put_contents($base.'/'.$relative.'/Installed.php', "<?php // installed\n");
    }
    mkdir($base.'/app/Legacy', 0777, true);
    file_put_contents($base.'/app/Legacy/Old.php', "<?php // retired by the release\n");
    mkdir($base.'/storage/app', 0777, true);
    mkdir($base.'/vendor/composer', 0777, true);
    file_put_contents($base.'/vendor/composer/autoload_classmap.php', '<?php return [];');

    $paths = new UpdatePaths($base, $base.'/storage');
    app()->instance(UpdatePaths::class, $paths);

    config(['magna.updater.allow_unsigned_checksum' => true]);

    return $paths;
}

function removeManifestInstall(UpdatePaths $paths): void
{
    (new Filesystem)->remove($paths->basePath);
}

/**
 * A release archive carrying a manifest. Every path in $paths gets one
 * delivered file; the manifest is the builder's own, with $overrides applied
 * a section at a time.
 *
 * @return array{bytes: string, sha256: string, manifest: array<string, mixed>}
 */
function manifestArchive(array $paths, array $overrides = [], string $version = '99.0.0'): array
{
    $manifest = release_manifest(
        $version,
        $paths,
        [],
        'v1.2.0',
        4096,
        ['commit' => null, 'tag' => null],
        gmdate('c'),
    );

    // The builder's check files are the real release's; the fixture promises
    // nothing unless a test says so, and the SDK paths are optional here.
    $manifest['checks']['files'] = [];
    $manifest['paths']['optional'] = [CoreUpdater::SDK_PATH, CoreUpdater::SDK_SOURCE_PATH];

    foreach ($overrides as $key => $value) {
        if (is_array($value) && isset($manifest[$key]) && is_array($manifest[$key]) && ! array_is_list($value)) {
            $manifest[$key] = array_replace($manifest[$key], $value);
        } else {
            $manifest[$key] = $value;
        }
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'magna-manifest-zip-');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);

    foreach ($paths as $relative) {
        if (in_array($relative, $manifest['paths']['optional'], true)) {
            continue;
        }
        $zip->addFromString($relative.'/Delivered.php', "<?php // delivered by the release\n");
    }
    $zip->addFromString(ReleaseManifest::FILENAME, (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->close();

    $bytes = (string) file_get_contents($zipPath);
    @unlink($zipPath);

    return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'manifest' => $manifest];
}

function applyManifestArchive(array $archive, string $version = '99.0.0'): CoreUpdateState
{
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    return app(CoreUpdater::class)->apply($version, 'https://github.com/magna-cms/magna/archive/v'.$version.'.zip', $archive['sha256']);
}

function manifestRunLog(UpdatePaths $paths): string
{
    $logs = glob($paths->runsDir().'/*/log.txt') ?: [];

    return $logs === [] ? '' : (string) file_get_contents($logs[0]);
}

it('delivers a path the updater has never heard of because the release names it', function (): void {
    $paths = manifestInstall();
    $archive = manifestArchive([...MANIFEST_TEST_PATHS, 'database/seeders']);

    try {
        expect(in_array('database/seeders', CoreUpdater::coreOwnedPaths(), true))->toBeFalse();

        $state = applyManifestArchive($archive);

        expect($state)->toBe(CoreUpdateState::Completed)
            ->and(is_file($paths->base('database/seeders/Delivered.php')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Delivered.php')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeFalse();

        $footprint = (new InstalledFootprint($paths))->read();

        expect($footprint)->not->toBeNull()
            ->and($footprint['version'] ?? null)->toBe('99.0.0')
            ->and($footprint['installed_via'] ?? null)->toBe('update')
            ->and($footprint['paths'] ?? [])->toContain('database/seeders')
            ->and($footprint['manifest_sha256'] ?? null)->toMatch('/^[a-f0-9]{64}$/');

        // The snapshot knows what it holds.
        $backups = glob($paths->backupsDir().'/*') ?: [];
        expect($backups)->toHaveCount(1)
            ->and(json_decode((string) file_get_contents($backups[0].'/paths.json'), true))->toContain('database/seeders');

        expect(manifestRunLog($paths))->toContain('Release manifest read');
    } finally {
        removeManifestInstall($paths);
    }
});

it('refuses a release whose archive lacks a path its manifest promises', function (): void {
    $paths = manifestInstall();
    // The manifest names routes; the archive builder is told routes is
    // optional-less but never packs it.
    $archive = manifestArchive([...MANIFEST_TEST_PATHS], ['paths' => ['core_owned' => [...MANIFEST_TEST_PATHS, 'database/seeders']]]);

    try {
        $state = applyManifestArchive($archive);

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('does not contain database/seeders')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(manifestRunLog($paths))->not->toContain('Maintenance mode on')
            ->and(glob($paths->backupsDir().'/*') ?: [])->toBe([]);
    } finally {
        removeManifestInstall($paths);
    }
});

it('refuses an archive that is not the release the hub announced', function (): void {
    $paths = manifestInstall();
    $archive = manifestArchive(MANIFEST_TEST_PATHS, [], version: '98.0.0');

    try {
        $state = applyManifestArchive($archive, '99.0.0');

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('says it is v98.0.0 but v99.0.0 was announced')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue();
    } finally {
        removeManifestInstall($paths);
    }
});

it('refuses a release whose requirements this host does not meet', function (): void {
    $paths = manifestInstall();
    $archive = manifestArchive(MANIFEST_TEST_PATHS, ['requires' => ['php' => '>=99.0.0']]);

    try {
        $state = applyManifestArchive($archive);

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('needs PHP >=99.0.0')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue();
    } finally {
        removeManifestInstall($paths);
    }
});

it('refuses a manifest that names a path an update may never own', function (): void {
    $paths = manifestInstall();
    $archive = manifestArchive(MANIFEST_TEST_PATHS, ['paths' => ['core_owned' => [...MANIFEST_TEST_PATHS, 'storage']]]);

    try {
        $state = applyManifestArchive($archive);

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('site-owned')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue();
    } finally {
        removeManifestInstall($paths);
    }
});

it('removes what the release retired and remembers the retired classes', function (): void {
    $paths = manifestInstall();
    $archive = manifestArchive(MANIFEST_TEST_PATHS, [
        'paths' => ['removed' => ['app/Legacy']],
        'removed_classes' => ['Magna\\Admin\\Pages\\GeneralSettingsPage', 'App\\Legacy\\Old', 'Magna\\Never\\InThisMap'],
    ]);

    // The site's map names two of the three; only those two need disarming.
    file_put_contents($paths->base('vendor/composer/autoload_classmap.php'), "<?php return ['Magna\\\\Admin\\\\Pages\\\\GeneralSettingsPage' => '/gone/GeneralSettingsPage.php', 'App\\\\Legacy\\\\Old' => '/gone/Old.php'];");

    try {
        $state = applyManifestArchive($archive);

        expect($state)->toBe(CoreUpdateState::Completed)
            ->and(is_dir($paths->base('app/Legacy')))->toBeFalse();

        // The snapshot took the retired path with it, so a rollback would put it back.
        $backups = glob($paths->backupsDir().'/*') ?: [];
        expect(is_file($backups[0].'/app/Legacy/Old.php'))->toBeTrue();

        // The next boot on 99.0.0 knows which classmap entries to disarm without scanning.
        $cache = json_decode((string) file_get_contents($paths->storage('framework/cache/magna-stale-classmap.json')), true);
        expect($cache['version'] ?? null)->toBe('99.0.0')
            ->and($cache['stale'] ?? [])->toBe(['App\\Legacy\\Old', 'Magna\\Admin\\Pages\\GeneralSettingsPage']);
    } finally {
        removeManifestInstall($paths);
    }
});

it('rolls back when a file the release promised is not there after the overlay', function (): void {
    $paths = manifestInstall();
    $archive = manifestArchive(MANIFEST_TEST_PATHS, ['checks' => ['files' => ['src/Magna/Promised.php']]]);

    try {
        $state = applyManifestArchive($archive);

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('files were restored')
            ->and(CoreUpdater::progress()['message'])->toContain('src/Magna/Promised.php')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Delivered.php')))->toBeFalse()
            ->and(app()->maintenanceMode()->active())->toBeFalse();
    } finally {
        removeManifestInstall($paths);
    }
});
