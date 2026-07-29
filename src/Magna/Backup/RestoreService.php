<?php

declare(strict_types=1);

namespace Magna\Backup;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Magna\Backup\Exceptions\RestoreFailedException;
use Magna\Settings\BackupSettings;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Stateful, single-use: prepare() extracts the archive, restoreDatabase()
 * and restoreFiles() apply it, cleanup() always removes the temp extraction
 * — call them in that order from one RestoreBackupJob run. Split into
 * separate methods (rather than one restore() call) so the job can tell
 * whether the database was touched yet when something fails, which decides
 * whether it's safe to bring the instance back out of maintenance mode — see
 * RestoreBackupJob and docs/backup-manager-plan.md, Stage 8.
 *
 * Deliberately narrow scope, matching Magna\Updater\CoreUpdater's own
 * documented limits: file-restore only touches storage/app (media), never
 * vendor/, migrations, or .env — those are the deploying/migrating
 * process's job, not a backup's. And there is no automated rollback of a
 * failed database restore, for the same reason CoreUpdater doesn't attempt
 * one either (see that class's docblock): a generic, safe, cross-driver DB
 * rollback is a much harder problem than this feature needs to solve to be
 * useful. The manual procedure (docs/backup-restore-guide.md) is always the
 * fallback.
 */
class RestoreService
{
    /**
     * Generous on purpose — a real large-site backup can legitimately be
     * huge, and this guard exists to catch a zip claiming to be far bigger
     * than any real backup archive would be, not to second-guess normal
     * large ones. 50 GB uncompressed.
     */
    private const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024 * 1024;

    private ?string $extractDir = null;

    private ?string $tempZipPath = null;

    /** Overridable in tests, so the guard can be exercised without an actual 50 GB archive. */
    public function __construct(private readonly int $maxUncompressedBytes = self::MAX_UNCOMPRESSED_BYTES) {}

    /** Restore from one of this Magna instance's own recorded backup runs. */
    public function prepare(BackupRun $run, bool $useSecondary): void
    {
        $diskName = $useSecondary ? $run->secondary_disk : $run->disk;
        $path = $useSecondary ? $run->secondary_path : $run->path;

        if ($run->status !== BackupRun::STATUS_SUCCESS || $diskName === null || $path === null) {
            throw RestoreFailedException::noArchive();
        }

        $this->prepareFromDiskPath($diskName, $path);
    }

    /**
     * Restore from an arbitrary archive on a disk — used for Import (an
     * admin-uploaded .zip not tied to one of this Magna instance's own `backup_runs`
     * rows, e.g. brought in from another environment). Same extraction and
     * decryption path as prepare(); the caller is responsible for validating
     * the upload itself (file type, size) before this ever runs.
     */
    public function prepareFromDiskPath(string $diskName, string $path, ?string $encryptionPassword = null): void
    {
        $disk = Storage::disk($diskName);

        if (! $disk->exists($path)) {
            throw RestoreFailedException::archiveMissing();
        }

        $this->tempZipPath = tempnam(sys_get_temp_dir(), 'magna-restore-').'.zip';
        file_put_contents($this->tempZipPath, $disk->get($path));

        $extractDir = storage_path('app/magna-restore-tmp/'.uniqid());
        $this->extractDir = $extractDir;
        (new Filesystem)->makeDirectory($extractDir, 0755, true, true);

        $zip = new ZipArchive;

        if ($zip->open($this->tempZipPath) !== true) {
            throw RestoreFailedException::corruptArchive();
        }

        // Explicit password (Import lets an admin supply one, since an
        // uploaded archive may not have been encrypted with this instance's own
        // configured password) wins; otherwise fall back to the instance's own.
        $password = $encryptionPassword ?? BackupSettings::get()->encryption_password;
        if ($password !== null) {
            $zip->setPassword($password);
        }

        try {
            $this->guardAgainstOversizedArchive($zip);
            $this->guardAgainstUnsafePaths($zip);

            if (! $zip->extractTo($extractDir)) {
                throw RestoreFailedException::corruptArchive();
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Defence in depth against zip-slip. A backup may be *imported* from an
     * untrusted third party (Import accepts an externally-supplied archive), so
     * reject any entry whose name could escape the extraction directory —
     * traversal (`..`), an absolute path, or a null byte — before extractTo()
     * touches disk. Mirrors the plugin installer's ZipSafeExtractor so the same
     * class of operation is held to the same bar, rather than relying on the
     * PHP/libzip version's own sanitisation.
     */
    private function guardAgainstUnsafePaths(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                throw RestoreFailedException::corruptArchive();
            }

            if (
                str_contains($name, '..')
                || str_starts_with($name, '/')
                || str_starts_with($name, '\\')
                || str_contains($name, "\0")
            ) {
                throw RestoreFailedException::unsafeArchivePath($name);
            }
        }
    }

    /**
     * A zip's compressed size on disk says nothing about how much it
     * expands to — a small, either malicious or simply corrupt, archive can
     * claim gigabytes of uncompressed content per entry ("zip bomb"),
     * exhausting disk before extractTo() ever returns. Checked against the
     * archive's own central-directory metadata (cheap — no decompression
     * needed to read it), before any bytes are actually extracted.
     */
    private function guardAgainstOversizedArchive(ZipArchive $zip): void
    {
        $totalUncompressedBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $totalUncompressedBytes += $stat !== false ? $stat['size'] : 0;

            if ($totalUncompressedBytes > $this->maxUncompressedBytes) {
                throw RestoreFailedException::archiveTooLarge();
            }
        }
    }

    public function restoreDatabase(): void
    {
        if ($this->extractDir === null) {
            throw new LogicException('prepare() must run before restoreDatabase().');
        }

        $dumpFiles = glob($this->extractDir.'/db-dumps/*.sql') ?: [];
        if ($dumpFiles === []) {
            return; // this backup didn't include a database — nothing to restore
        }

        $connection = Config::string('database.default');
        $driver = DB::connection($connection)->getDriverName();
        $dumpFile = $dumpFiles[0];

        $process = match ($driver) {
            'sqlite' => $this->sqliteRestoreProcess($connection, $dumpFile),
            'mysql', 'mariadb' => $this->mysqlRestoreProcess($connection, $dumpFile),
            'pgsql' => $this->pgsqlRestoreProcess($connection, $dumpFile),
            default => throw RestoreFailedException::unsupportedDriver($driver),
        };

        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw RestoreFailedException::databaseRestoreFailed($process->getErrorOutput());
        }
    }

    public function restoreFiles(): void
    {
        if ($this->extractDir === null) {
            throw new LogicException('prepare() must run before restoreFiles().');
        }

        $source = $this->extractDir.'/storage/app';
        if (! is_dir($source)) {
            return; // this backup didn't include files, or included none under storage/app
        }

        (new Filesystem)->copyDirectory($source, storage_path('app'));
    }

    public function cleanup(): void
    {
        if ($this->extractDir !== null) {
            (new Filesystem)->deleteDirectory($this->extractDir);
            $this->extractDir = null;
        }

        if ($this->tempZipPath !== null) {
            @unlink($this->tempZipPath);
            $this->tempZipPath = null;
        }
    }

    private function sqliteRestoreProcess(string $connection, string $dumpFile): Process
    {
        $databasePath = $this->connectionConfigString($connection, 'database');

        if ($databasePath === ':memory:') {
            throw RestoreFailedException::inMemoryDatabase();
        }

        $handle = fopen($dumpFile, 'r');
        if ($handle === false) {
            throw RestoreFailedException::corruptArchive();
        }

        $process = new Process(['sqlite3', $databasePath]);
        $process->setInput($handle);

        return $process;
    }

    private function mysqlRestoreProcess(string $connection, string $dumpFile): Process
    {
        $handle = fopen($dumpFile, 'r');
        if ($handle === false) {
            throw RestoreFailedException::corruptArchive();
        }

        $command = ['mysql', '-h', $this->connectionConfigString($connection, 'host', '127.0.0.1')];

        $port = $this->connectionConfigString($connection, 'port');
        if ($port !== '') {
            $command[] = '-P';
            $command[] = $port;
        }

        $command[] = '-u';
        $command[] = $this->connectionConfigString($connection, 'username');
        $command[] = $this->connectionConfigString($connection, 'database');

        $process = new Process($command, env: [
            // Avoids the password ever appearing in the process list, unlike a -p<pass> argv entry.
            'MYSQL_PWD' => $this->connectionConfigString($connection, 'password'),
        ]);
        $process->setInput($handle);

        return $process;
    }

    private function pgsqlRestoreProcess(string $connection, string $dumpFile): Process
    {
        $command = [
            'psql',
            '-h', $this->connectionConfigString($connection, 'host', '127.0.0.1'),
            '-U', $this->connectionConfigString($connection, 'username'),
            '-d', $this->connectionConfigString($connection, 'database'),
            '-f', $dumpFile,
        ];

        return new Process($command, env: [
            'PGPASSWORD' => $this->connectionConfigString($connection, 'password'),
        ]);
    }

    /**
     * Config::string() throws if the stored value isn't literally a string —
     * fine for most config, but connection ports are sometimes stored as an
     * int (e.g. a hardcoded `'port' => 3306`), and this needs to keep working
     * either way rather than crash a restore over a formatting difference.
     */
    private function connectionConfigString(string $connection, string $key, string $default = ''): string
    {
        $value = Config::get("database.connections.{$connection}.{$key}", $default);

        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            default => $default,
        };
    }
}
