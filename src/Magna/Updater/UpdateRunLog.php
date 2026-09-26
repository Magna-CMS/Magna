<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Support\Facades\Log;
use Magna\MagnaServiceProvider;

/**
 * A durable record of one update run.
 *
 * The progress entry the panel reads lives in the cache with a 30-minute
 * TTL and is overwritten by the next run; the application log is whatever
 * the host rotates. When an update went wrong on a customer's site the only
 * evidence used to be the admin's memory of the last message they saw. Every
 * step now also lands in storage/app/magna-updates/runs/<run>/log.txt, next
 * to the snapshot it belongs with, and in the application log under one
 * searchable prefix.
 *
 * Best effort by design: an unwritable runs/ directory must not stop an
 * update, so writes are silenced and the Laravel log remains.
 */
final class UpdateRunLog
{
    private ?string $runId = null;

    private ?string $path = null;

    public function __construct(private readonly UpdatePaths $paths) {}

    /** Opens a new run and returns its id (a timestamp plus a short random suffix). */
    public function start(string $targetVersion): string
    {
        $runId = now()->format('Ymd_His').'_'.bin2hex(random_bytes(2));
        $this->runId = $runId;

        $directory = $this->paths->runsDir().'/'.$runId;

        if (is_dir($directory) || @mkdir($directory, 0700, true) || is_dir($directory)) {
            $this->path = $directory.'/log.txt';
        }

        $this->line('Applying v'.ltrim($targetVersion, 'vV').' over v'.MagnaServiceProvider::VERSION.' (run '.$runId.')');

        return $runId;
    }

    /**
     * Continue the log of a run another process started — the finalizer on
     * the new code, a shell resuming a stalled switch.
     */
    public function resume(string $runId): void
    {
        $this->runId = $runId;

        $directory = $this->paths->runsDir().'/'.$runId;

        $this->path = (is_dir($directory) || @mkdir($directory, 0700, true) || is_dir($directory))
            ? $directory.'/log.txt'
            : null;
    }

    public function line(string $message): void
    {
        Log::info('[magna.updater] '.$message, ['run' => $this->runId]);

        if ($this->path === null) {
            return;
        }

        @file_put_contents(
            $this->path,
            now()->toIso8601String().' '.$message.PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    public function runId(): ?string
    {
        return $this->runId;
    }

    public function path(): ?string
    {
        return $this->path;
    }
}
