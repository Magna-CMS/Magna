<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Magna\Backup\BackupRun;
use Magna\Backup\Exceptions\RestoreFailedException;
use Magna\Backup\RestoreService;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// RestoreBackupJob itself (Artisan down/up, real DB mutation) is
// deliberately never executed in this suite — see
// docs/backup-manager-plan.md, Stage 8: there is no disposable environment
// available here, and running a real restore against the only available
// (real dev) app/DB is exactly the risk that stage's checklist warns
// against. These tests exercise RestoreService directly, which never
// touches Artisan and only mutates the filesystem under storage/app in a
// way that's cleaned up below — no live DB write happens because the
// in-memory-DB guard trips first in this test environment (DB_DATABASE is
// ":memory:" per phpunit.xml), which is itself one of the things verified.

beforeEach(function (): void {
    Cache::flush();
});

afterEach(function (): void {
    Storage::disk('public')->deleteDirectory('restore-test');
    @unlink(storage_path('app/magna-restore-test-marker.txt'));
});

/**
 * @param  array<string, string>  $entries  relative zip path => file contents
 */
function makeTestArchive(array $entries): BackupRun
{
    $zipPath = tempnam(sys_get_temp_dir(), 'magna-restore-fixture-').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    $storedPath = 'restore-test/'.uniqid().'.zip';
    Storage::disk('public')->put($storedPath, (string) file_get_contents($zipPath));
    @unlink($zipPath);

    return BackupRun::create([
        'type' => BackupRun::TYPE_MANUAL,
        'status' => BackupRun::STATUS_SUCCESS,
        'disk' => 'public',
        'path' => $storedPath,
    ]);
}

it('throws when the run has no successful archive', function (): void {
    $run = BackupRun::create(['type' => BackupRun::TYPE_MANUAL, 'status' => BackupRun::STATUS_FAILED]);

    expect(fn () => (new RestoreService)->prepare($run, false))
        ->toThrow(RestoreFailedException::class);
});

it('throws when the archive file no longer exists on its disk', function (): void {
    $run = BackupRun::create([
        'type' => BackupRun::TYPE_MANUAL,
        'status' => BackupRun::STATUS_SUCCESS,
        'disk' => 'public',
        'path' => 'restore-test/does-not-exist.zip',
    ]);

    expect(fn () => (new RestoreService)->prepare($run, false))
        ->toThrow(RestoreFailedException::class);
});

it('throws when the archive is corrupt', function (): void {
    Storage::disk('public')->put('restore-test/corrupt.zip', 'this is not a zip file');
    $run = BackupRun::create([
        'type' => BackupRun::TYPE_MANUAL,
        'status' => BackupRun::STATUS_SUCCESS,
        'disk' => 'public',
        'path' => 'restore-test/corrupt.zip',
    ]);

    expect(fn () => (new RestoreService)->prepare($run, false))
        ->toThrow(RestoreFailedException::class);
});

it('requires prepare() before restoreDatabase() or restoreFiles()', function (): void {
    $service = new RestoreService;

    expect(fn () => $service->restoreDatabase())->toThrow(LogicException::class);
    expect(fn () => $service->restoreFiles())->toThrow(LogicException::class);
});

it('no-ops restoreDatabase() when the archive has no database dump', function (): void {
    $run = makeTestArchive(['storage/app/marker.txt' => 'hello']);

    $service = new RestoreService;
    $service->prepare($run, false);

    // Must not throw — nothing to restore is not an error.
    $service->restoreDatabase();
    expect(true)->toBeTrue();

    $service->cleanup();
});

it('refuses to restore into an in-memory sqlite connection', function (): void {
    // The guard under test exists only for an in-memory SQLite connection:
    // there is no file to restore into. CI also runs this suite against MySQL
    // and Postgres, where the guard cannot trip and the assertion below is
    // simply false — the test was written when SQLite was the only driver the
    // suite ever saw.
    //
    // Worth naming the trap: on a local MySQL run this passed anyway, because
    // no mysql client binary was installed and restoreDatabase() threw
    // RestoreFailedException for that unrelated reason. CI has the binary, so
    // it failed there. A green local run was not evidence.
    $connection = DB::connection();

    if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
        $this->markTestSkipped('The in-memory SQLite guard is unreachable on the '.$connection->getDriverName().' connection.');
    }

    $run = makeTestArchive(['db-dumps/sqlite-database.sql' => 'SELECT 1;']);

    $service = new RestoreService;
    $service->prepare($run, false);

    expect(fn () => $service->restoreDatabase())->toThrow(RestoreFailedException::class);

    $service->cleanup();
});

it('no-ops restoreFiles() when the archive has no storage/app directory', function (): void {
    $run = makeTestArchive(['db-dumps/sqlite-database.sql' => 'SELECT 1;']);

    $service = new RestoreService;
    $service->prepare($run, false);
    $service->restoreFiles(); // must not throw
    $service->cleanup();

    expect(file_exists(storage_path('app/magna-restore-test-marker.txt')))->toBeFalse();
});

it('copies storage/app contents from the archive back into place', function (): void {
    $run = makeTestArchive(['storage/app/magna-restore-test-marker.txt' => 'restored-content']);

    $service = new RestoreService;
    $service->prepare($run, false);
    $service->restoreFiles();
    $service->cleanup();

    $restoredPath = storage_path('app/magna-restore-test-marker.txt');
    expect(file_exists($restoredPath))->toBeTrue()
        ->and(file_get_contents($restoredPath))->toBe('restored-content');
});

it('cleanup() removes the temporary extraction directory', function (): void {
    $run = makeTestArchive(['storage/app/marker.txt' => 'hello']);

    $service = new RestoreService;
    $service->prepare($run, false);

    $reflection = new ReflectionClass($service);
    $extractDirProperty = $reflection->getProperty('extractDir');
    $extractDirProperty->setAccessible(true);
    $extractDir = $extractDirProperty->getValue($service);

    expect(is_dir($extractDir))->toBeTrue();

    $service->cleanup();

    expect(is_dir($extractDir))->toBeFalse();
});
