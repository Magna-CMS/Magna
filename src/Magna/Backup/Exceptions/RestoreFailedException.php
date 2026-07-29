<?php

declare(strict_types=1);

namespace Magna\Backup\Exceptions;

use RuntimeException;

class RestoreFailedException extends RuntimeException
{
    public static function noArchive(): self
    {
        return new self('This backup run has no successful archive to restore from.');
    }

    public static function archiveMissing(): self
    {
        return new self('The archive file no longer exists on its destination disk.');
    }

    public static function corruptArchive(): self
    {
        return new self('The archive could not be opened — it may be corrupt, or the encryption password is wrong.');
    }

    public static function archiveTooLarge(): self
    {
        return new self('The archive claims an implausibly large amount of uncompressed content and was refused before extraction — it may be corrupt or a zip bomb.');
    }

    public static function unsafeArchivePath(string $entry): self
    {
        return new self("The archive contains an unsafe path (\"{$entry}\") that could escape the extraction directory, and was refused. Do not restore backups from untrusted sources.");
    }

    public static function inMemoryDatabase(): self
    {
        return new self('Cannot restore into an in-memory (:memory:) database connection — there is nothing to write to on disk.');
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("Restoring a '{$driver}' database is not supported. Supported: sqlite, mysql, pgsql.");
    }

    public static function databaseRestoreFailed(string $errorOutput): self
    {
        $trimmed = trim($errorOutput);

        return new self('The database restore command failed: '.($trimmed !== '' ? $trimmed : 'no error output was captured.'));
    }
}
