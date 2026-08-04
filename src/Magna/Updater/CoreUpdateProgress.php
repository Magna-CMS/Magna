<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Support\Facades\Cache;

/**
 * Owns the cache entry the update UI reads: current step, a rough percentage,
 * and the ordered list of steps taken so far so the admin can see the
 * background activity instead of one opaque line.
 *
 * Extracted from CoreUpdater so the updater keeps orchestrating the apply and
 * this class keeps the reporting/serialisation shape in one place (same split
 * as Magna\Backup\BackupRunOutcomeRecorder vs RunBackupJob).
 */
final class CoreUpdateProgress
{
    private const KEY = 'magna.updater.apply.progress';

    /** Kept separate from the progress entry: cleared on first use, never shown to the browser. */
    private const PENDING_KEY = 'magna.updater.apply.pending';

    private const TTL = 1800;

    /** Enough to show the whole apply; capped so a release loop can't grow the entry without bound. */
    private const LOG_LIMIT = 16;

    public static function set(CoreUpdateState $state, string $message, int $percent = 0, ?string $version = null): void
    {
        $current = self::raw();

        $log = self::appendLog(
            is_array($current['log'] ?? null) ? $current['log'] : [],
            $message,
            $percent,
        );

        Cache::put(self::KEY, [
            'state' => $state->value,
            'message' => $message,
            'percent' => max(0, min(100, $percent)),
            'version' => $version ?? (is_string($current['version'] ?? null) ? $current['version'] : null),
            'log' => $log,
            'queued_at' => is_int($current['queued_at'] ?? null) ? $current['queued_at'] : null,
        ], self::TTL);
    }

    /**
     * Records an update as waiting to be picked up, before the job is dispatched.
     *
     * CoreUpdateJob is queued, so on a site whose QUEUE_CONNECTION is not `sync`
     * nothing happens until a worker runs it. Without this the UI sat on
     * "Starting…" indefinitely and read as a broken update rather than a missing
     * worker: apply() had never been entered, so nothing had written progress at
     * all. The release itself is stored alongside so a later request can carry
     * out the apply directly if no worker ever shows up.
     */
    public static function markQueued(PendingCoreUpdate $pending): void
    {
        Cache::put(self::PENDING_KEY, $pending->toArray(), self::TTL);

        Cache::put(self::KEY, [
            'state' => CoreUpdateState::Queued->value,
            'message' => 'Queued v'.$pending->version.' — waiting for a background worker.',
            'percent' => 1,
            'version' => $pending->version,
            'log' => [['message' => 'Queued v'.$pending->version.'.', 'percent' => 1]],
            'queued_at' => now()->getTimestamp(),
        ], self::TTL);
    }

    /**
     * @return array{state: string|null, message: string, percent: int, version: string|null, log: list<array{message: string, percent: int}>, waiting_seconds: int}
     */
    public static function read(): array
    {
        $value = self::raw();

        $state = is_string($value['state'] ?? null) ? $value['state'] : null;
        $message = is_string($value['message'] ?? null) ? $value['message'] : '';
        $queuedAt = is_int($value['queued_at'] ?? null) ? $value['queued_at'] : null;

        return [
            'state' => $state,
            'message' => $message,
            'percent' => is_int($value['percent'] ?? null) ? $value['percent'] : 0,
            'version' => is_string($value['version'] ?? null) ? $value['version'] : null,
            'log' => self::sanitizeLog($value['log'] ?? null),
            'waiting_seconds' => $queuedAt === null ? 0 : max(0, now()->getTimestamp() - $queuedAt),
        ];
    }

    /** The release a queued apply is about, if one is still waiting. */
    public static function pending(): ?PendingCoreUpdate
    {
        $value = Cache::get(self::PENDING_KEY);

        return is_array($value) ? PendingCoreUpdate::fromArray($value) : null;
    }

    /**
     * Consumes the pending release: returns it and removes it in one step, so a
     * page polling every couple of seconds can only ever start the fallback
     * apply once.
     */
    public static function takePending(): ?PendingCoreUpdate
    {
        // pull(), not get()+forget(): keeps the window in which two concurrent
        // polls could both see the same record as small as the cache store
        // allows. apply()'s own lock is the backstop if they still overlap.
        $value = Cache::pull(self::PENDING_KEY);

        return is_array($value) ? PendingCoreUpdate::fromArray($value) : null;
    }

    public static function clearPending(): void
    {
        Cache::forget(self::PENDING_KEY);
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
        self::clearPending();
    }

    /** @return array<string, mixed> */
    private static function raw(): array
    {
        $value = Cache::get(self::KEY);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<mixed>  $log
     * @return list<array{message: string, percent: int}>
     */
    private static function appendLog(array $log, string $message, int $percent): array
    {
        $entries = self::sanitizeLog($log);
        $last = $entries === [] ? null : $entries[count($entries) - 1];

        if ($last !== null && $last['message'] === $message) {
            return $entries;
        }

        $entries[] = ['message' => $message, 'percent' => max(0, min(100, $percent))];

        return array_slice($entries, -self::LOG_LIMIT);
    }

    /** @return list<array{message: string, percent: int}> */
    private static function sanitizeLog(mixed $log): array
    {
        if (! is_array($log)) {
            return [];
        }

        $entries = [];

        foreach ($log as $entry) {
            if (! is_array($entry) || ! is_string($entry['message'] ?? null)) {
                continue;
            }

            $entries[] = [
                'message' => $entry['message'],
                'percent' => is_int($entry['percent'] ?? null) ? max(0, min(100, $entry['percent'])) : 0,
            ];
        }

        return $entries;
    }
}
