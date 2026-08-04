<?php

declare(strict_types=1);

namespace Magna\Marketplace;

use Illuminate\Support\Facades\Cache;

/**
 * Owns the per-package cache entry the Plugins page polls while an install
 * runs, plus the server-side record of which packages this install actually
 * queued.
 *
 * Extracted from PluginInstaller so the installer keeps orchestrating the
 * install and this class keeps the reporting shape in one place — same split as
 * Magna\Updater\CoreUpdateProgress vs CoreUpdater.
 */
final class InstallProgress
{
    private const TTL = 900;

    public static function set(string $package, InstallState $state, string $message): void
    {
        $current = self::raw($package);

        Cache::put(self::key($package), [
            'state' => $state->value,
            'message' => $message,
            'queued_at' => is_int($current['queued_at'] ?? null) ? $current['queued_at'] : null,
        ], self::TTL);
    }

    /**
     * Records an install as waiting to be picked up, before the job is dispatched.
     *
     * InstallPluginJob is queued (ShouldQueue), so on a site whose
     * QUEUE_CONNECTION is not `sync` nothing happens until a worker runs it.
     * Without this the Plugins page sat on "Queued…" indefinitely and read as a
     * broken install rather than a missing worker: install() had never been
     * entered, so nothing had written progress at all.
     *
     * The package is also recorded server-side, so the stalled-install fallback
     * can only ever act on a package this install queued itself — never one a
     * browser put into the page's `installQueue` property.
     */
    public static function markQueued(string $package, ?string $message = null): void
    {
        Cache::put(self::pendingKey($package), true, self::TTL);

        Cache::put(self::key($package), [
            'state' => InstallState::Queued->value,
            'message' => $message ?? 'Queued — waiting for a background worker.',
            'queued_at' => now()->getTimestamp(),
        ], self::TTL);
    }

    /** @return array{state: string|null, message: string, waiting_seconds: int} */
    public static function read(string $package): array
    {
        $value = self::raw($package);

        $queuedAt = is_int($value['queued_at'] ?? null) ? $value['queued_at'] : null;

        return [
            'state' => is_string($value['state'] ?? null) ? $value['state'] : null,
            'message' => is_string($value['message'] ?? null) ? $value['message'] : '',
            'waiting_seconds' => $queuedAt === null ? 0 : max(0, now()->getTimestamp() - $queuedAt),
        ];
    }

    public static function isPending(string $package): bool
    {
        return Cache::get(self::pendingKey($package)) !== null;
    }

    /**
     * Claims the queued package: returns whether this caller got it, and
     * removes the record either way. pull(), not get()+forget(), so a page
     * polling every two seconds cannot start the same install twice.
     */
    public static function claimPending(string $package): bool
    {
        return Cache::pull(self::pendingKey($package)) !== null;
    }

    public static function clearPending(string $package): void
    {
        Cache::forget(self::pendingKey($package));
    }

    public static function forget(string $package): void
    {
        Cache::forget(self::key($package));
        self::clearPending($package);
    }

    /** @return array<string, mixed> */
    private static function raw(string $package): array
    {
        $value = Cache::get(self::key($package));

        return is_array($value) ? $value : [];
    }

    private static function key(string $package): string
    {
        return 'magna.marketplace.install.'.$package;
    }

    private static function pendingKey(string $package): string
    {
        return 'magna.marketplace.install.pending.'.$package;
    }
}
