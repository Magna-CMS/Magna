<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Magna\Backup\Exceptions\RestoreFailedException;
use Magna\Backup\RestoreService;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Covers RestoreService::prepareFromDiskPath() — the Import path, which
// (unlike prepare()) isn't tied to a backup_runs row at all. Same safety
// posture as RestoreServiceTest.php: no Artisan down/up, no live DB writes
// (the in-memory-DB guard on restoreDatabase() trips first in this test
// environment either way).

beforeEach(function (): void {
    Cache::flush();
});

afterEach(function (): void {
    Storage::disk('local')->deleteDirectory('import-test');
});

/**
 * @param  array<string, string>  $entries
 */
function makeImportArchive(array $entries, ?string $password = null): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'magna-import-fixture-').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    if ($password !== null) {
        $zip->setPassword($password);
    }
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
        if ($password !== null) {
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256);
        }
    }
    $zip->close();

    $storedPath = 'import-test/'.uniqid().'.zip';
    Storage::disk('local')->put($storedPath, (string) file_get_contents($zipPath));
    @unlink($zipPath);

    return $storedPath;
}

it('restores from an uploaded archive not tied to any backup_runs row', function (): void {
    $path = makeImportArchive(['storage/app/magna-import-test-marker.txt' => 'imported-content']);

    $service = new RestoreService;
    $service->prepareFromDiskPath('local', $path);
    $service->restoreFiles();
    $service->cleanup();

    $restoredPath = storage_path('app/magna-import-test-marker.txt');
    expect(file_exists($restoredPath))->toBeTrue()
        ->and(file_get_contents($restoredPath))->toBe('imported-content');

    @unlink($restoredPath);
});

it('throws when the uploaded archive path does not exist', function (): void {
    $service = new RestoreService;

    expect(fn () => $service->prepareFromDiskPath('local', 'import-test/does-not-exist.zip'))
        ->toThrow(RestoreFailedException::class);
});

it('uses an explicit archive password over the site-configured one', function (): void {
    $path = makeImportArchive(['storage/app/magna-import-test-marker.txt' => 'secret-content'], password: 'uploaded-archive-password');

    $service = new RestoreService;
    // Explicit password, distinct from (and not requiring) any BackupSettings value.
    $service->prepareFromDiskPath('local', $path, 'uploaded-archive-password');
    $service->restoreFiles();
    $service->cleanup();

    $restoredPath = storage_path('app/magna-import-test-marker.txt');
    expect(file_exists($restoredPath))->toBeTrue();

    @unlink($restoredPath);
});

// ── Security: refuse an implausibly large archive before extracting ────────

it('refuses an archive containing a path-traversal entry (zip-slip)', function (): void {
    // An imported archive from an untrusted source could try to escape the
    // extraction directory. The guard must reject it before extractTo().
    $path = makeImportArchive(['../../evil.txt' => 'pwned']);

    $service = new RestoreService;

    expect(fn () => $service->prepareFromDiskPath('local', $path))
        ->toThrow(RestoreFailedException::class, 'unsafe path');
});

it('refuses to extract an archive whose uncompressed size exceeds the configured limit', function (): void {
    $path = makeImportArchive(['storage/app/magna-import-test-marker.txt' => str_repeat('x', 1000)]);

    // Real content is ~1KB uncompressed; a limit of 10 bytes must trip the guard.
    $service = new RestoreService(maxUncompressedBytes: 10);

    expect(fn () => $service->prepareFromDiskPath('local', $path))
        ->toThrow(RestoreFailedException::class);
});

it('extracts normally when comfortably under the configured limit', function (): void {
    $path = makeImportArchive(['storage/app/magna-import-test-marker.txt' => 'small content']);

    $service = new RestoreService(maxUncompressedBytes: 1_048_576); // 1 MB, plenty for this fixture
    $service->prepareFromDiskPath('local', $path);
    $service->restoreFiles();
    $service->cleanup();

    $restoredPath = storage_path('app/magna-import-test-marker.txt');
    expect(file_exists($restoredPath))->toBeTrue();

    @unlink($restoredPath);
});
