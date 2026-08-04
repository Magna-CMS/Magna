<?php

declare(strict_types=1);

namespace Magna\System;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Octane\OctaneServiceProvider;
use Magna\Backup\BackupRun;
use Magna\Marketplace\Marketplace;
use Magna\Settings\BackupSettings;
use Magna\Updater\CoreWritability;
use Throwable;

/**
 * Gathers runtime system-health metrics — backup freshness, boot time, cache
 * latency, opcache, queue depth, database version, and production performance
 * warnings. Extracted from SystemInfoPage so this diagnostics logic is a
 * plain, independently testable service rather than private methods buried in
 * a Filament page (which should only present, not compute).
 */
final class SystemHealthCollector
{
    /**
     * "Last successful backup: X ago", flagged as a warning once a
     * scheduled backup has silently stopped succeeding rather than only
     * distinguishing "never ran" — see docs/backup-manager-plan.md, Stage 6.
     *
     * Deliberately does NOT short-circuit on `enabled === false`: manual
     * runs ("Run backup now") work regardless of that toggle (it only
     * gates the *schedule*, per BackupSettingsPage's own tooltip on that
     * action), so a site backing up manually with automation off still has
     * real backup history worth showing — hiding it behind a blanket
     * "Disabled" was a real bug caught by testing this live (three
     * successful manual runs were completely invisible here until fixed).
     *
     * The staleness threshold is a fixed grace window per frequency
     * (`daily` → 2 days, `weekly` → 9 days), not a live read of the exact
     * next-due time — good enough to catch "this has been silently broken
     * for a while" without duplicating BackupSchedule's own due-window
     * logic here. `custom_cron` has no fixed interval to derive a grace
     * window from, so it falls back to the same 2-day threshold as
     * `daily` — a documented approximation, not a precise fit for every
     * possible cron expression. The staleness escalation itself only
     * applies when `enabled` is true — with no schedule promised, "stale
     * relative to what?" doesn't have an answer.
     *
     * @return array{color: 'ok'|'warning'|'neutral', label: string}
     */
    public function backupHealth(): array
    {
        $settings = BackupSettings::get();

        $last = BackupRun::query()
            ->where('status', BackupRun::STATUS_SUCCESS)
            ->orderByDesc('started_at')
            ->first();

        if ($last === null || $last->started_at === null) {
            return $settings->enabled
                ? ['color' => 'warning', 'label' => 'No successful backup yet']
                : ['color' => 'neutral', 'label' => 'Never run (automation disabled)'];
        }

        if (! $settings->enabled) {
            return ['color' => 'ok', 'label' => $last->started_at->diffForHumans().' (manual only — automation disabled)'];
        }

        $graceDays = $settings->frequency === 'weekly' ? 9 : 2;

        if ($last->started_at->lt(now()->subDays($graceDays))) {
            return ['color' => 'warning', 'label' => $last->started_at->diffForHumans().' (stale)'];
        }

        return ['color' => 'ok', 'label' => $last->started_at->diffForHumans()];
    }

    /**
     * Time since Laravel's front controller started (defined in
     * public/index.php) — the closest single number to "how much did booting
     * the framework cost this request," which is exactly what Octane
     * eliminates by keeping the app booted between requests. Without Octane
     * this is paid on every single page load; with it, only on worker start.
     */
    public function bootTimeMs(): float
    {
        $start = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        return round((microtime(true) - $start) * 1000, 1);
    }

    /**
     * Round-trip time for a real cache write+read on whatever driver is
     * currently configured — a more honest number than just "ok/error",
     * since a database-driver cache "working" can still be meaningfully
     * slower than Redis would be.
     */
    public function cacheLatencyMs(): ?float
    {
        try {
            $start = microtime(true);
            Cache::put('magna_perf_probe', 1, 5);
            Cache::get('magna_perf_probe');

            return round((microtime(true) - $start) * 1000, 2);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{available: bool, enabled: bool, hit_rate: ?float, memory_used_mb: ?float, memory_free_mb: ?float}
     */
    public function opcacheStatus(): array
    {
        if (! function_exists('opcache_get_status')) {
            return ['available' => false, 'enabled' => false, 'hit_rate' => null, 'memory_used_mb' => null, 'memory_free_mb' => null];
        }

        $status = @opcache_get_status(false);

        // False under CLI (opcache.enable_cli is normally off) and when
        // opcache.enable itself is off — both legitimate, not errors.
        if ($status === false) {
            return ['available' => false, 'enabled' => false, 'hit_rate' => null, 'memory_used_mb' => null, 'memory_free_mb' => null];
        }

        $stats = $status['opcache_statistics'] ?? [];
        $memory = $status['memory_usage'] ?? [];

        return [
            'available' => true,
            'enabled' => (bool) ($status['opcache_enabled'] ?? false),
            'hit_rate' => isset($stats['opcache_hit_rate']) ? round((float) $stats['opcache_hit_rate'], 1) : null,
            'memory_used_mb' => isset($memory['used_memory']) ? round($memory['used_memory'] / 1_048_576, 1) : null,
            'memory_free_mb' => isset($memory['free_memory']) ? round($memory['free_memory'] / 1_048_576, 1) : null,
        ];
    }

    /**
     * Jobs waiting in the "database" queue driver's table. Only meaningful
     * when the queue connection is actually "database" — for Redis or other
     * drivers this table simply isn't where jobs live, so we say so instead
     * of showing a misleading zero.
     */
    public function queuePendingCount(): ?int
    {
        if ($this->configString('queue.default', 'sync') !== 'database') {
            return null;
        }

        try {
            return Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * How long the oldest waiting job has been waiting, in minutes.
     *
     * A pending count on its own is ambiguous — one job could mean a healthy
     * queue caught mid-stride, or a worker that has not run since April. Age
     * is what separates them, and it is the question an admin is actually
     * asking when they see a number sitting there.
     *
     * Null when the count cannot be read or nothing is waiting.
     */
    public function queueOldestPendingMinutes(): ?int
    {
        if ($this->configString('queue.default', 'sync') !== 'database') {
            return null;
        }

        try {
            if (! Schema::hasTable('jobs')) {
                return null;
            }

            // available_at, not created_at: a delayed job is not late until
            // the time it was scheduled for has passed.
            $oldest = DB::table('jobs')->min('available_at');

            // Drivers report the aggregate as int or numeric string depending
            // on the PDO adapter; anything else means no usable timestamp.
            if (! is_numeric($oldest)) {
                return null;
            }

            return max(0, (int) floor((time() - (int) $oldest) / 60));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Failed jobs are logged to failed_jobs regardless of which queue
     * connection is active, so this count is meaningful no matter the driver.
     */
    public function queueFailedCount(): ?int
    {
        try {
            return Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Configuration that weakens a security control, reported where an admin
     * will actually see it.
     *
     * Deliberately separate from performanceWarnings(): these are not "the
     * site could be faster", they are "a control you believe is protecting you
     * is not", and both of the current entries fail *silently* — a build with
     * no licence key looks like an unreachable server, and a wildcard proxy
     * trust looks like nothing at all until the audit log is full of forged
     * IPs.
     *
     * @return list<array{label: string, help: string}>
     */
    public function securityWarnings(): array
    {
        $warnings = [];

        if (! Marketplace::hasUsableLicenseKey()) {
            $warnings[] = [
                'label' => 'No licence verification key is configured',
                'help' => 'Every licence response will fail signature verification, so no paid product can be activated or re-verified and licensed plugins will fall back to offline grace until it expires. This build is missing its Ed25519 public key — reinstall from an official release archive.',
            ];
        }

        if (config('app.trusted_proxies') === '*') {
            $warnings[] = [
                'label' => 'All proxies are trusted (TRUSTED_PROXIES=*)',
                'help' => 'X-Forwarded-For is accepted from any client, so the IP recorded in the audit log and used for login brute-force throttling can be set by the attacker. Correct for a single trusted reverse proxy that strips inbound forwarding headers; replace with an explicit CIDR list otherwise.',
            ];
        }

        return $warnings;
    }

    /**
     * Proactive "you're running sub-optimally" checks — only surfaced when
     * APP_ENV=production, so local/staging dev never gets nagged. This is
     * deliberately here (reaching every admin who opens System Info) rather
     * than only documented, since documentation only helps someone who
     * already knows to go looking for it.
     *
     * @return list<array{label: string, help: string}>
     */
    public function performanceWarnings(): array
    {
        if (! app()->environment('production')) {
            return [];
        }

        $warnings = [];

        $octaneInstalled = class_exists(OctaneServiceProvider::class);
        $octaneRunning = filter_var(getenv('LARAVEL_OCTANE'), FILTER_VALIDATE_BOOLEAN);

        if (! $octaneRunning) {
            $warnings[] = [
                'label' => 'Octane is not running',
                'help' => $octaneInstalled
                    ? 'The package is installed but the app is still served by plain PHP-FPM/CLI — every request re-boots the framework from scratch. See docs/DEPLOYMENT.md section 3A to start it under a process supervisor.'
                    : 'Running on plain PHP-FPM/CLI. Installing Octane (FrankenPHP) is the single biggest lever for admin-panel and API speed — see docs/DEPLOYMENT.md section 3A.',
            ];
        }

        $cacheDriver = $this->configString('cache.default', 'file');
        if (in_array($cacheDriver, ['file', 'database'], true)) {
            $warnings[] = [
                'label' => 'Cache driver is "'.$cacheDriver.'", not Redis',
                'help' => 'Every cache read/write costs a '.($cacheDriver === 'database' ? 'SQL query' : 'disk read').' instead of an in-memory lookup. Set this in Settings → Performance once a Redis server is reachable — .env alone does not change this (see the Performance settings guide for why).',
            ];
        }

        // Permissions drift after install (host migration, a hardening script, a
        // stray chown), so this is checked here too and not only on the
        // installer's requirements screen.
        $writability = new CoreWritability(base_path());
        $updateBlocker = $writability->summary();
        if ($updateBlocker !== null) {
            $warnings[] = [
                'label' => 'Core files are not writable — one-click updates will refuse to run',
                'help' => $updateBlocker.'. '.($writability->remedy() ?? '').' Until it is fixed, a new version has to be applied by re-uploading the release archive over this install.',
            ];
        }

        $queueConnection = $this->configString('queue.default', 'sync');
        if ($queueConnection === 'sync') {
            $warnings[] = [
                'label' => 'Queue connection is "sync"',
                'help' => 'Background jobs (media thumbnails, webhooks) run in-request instead of in the background, making uploads and other actions wait for them to finish. Switch to Redis or Database in Settings → Performance.',
            ];
        } elseif ($queueConnection === 'database') {
            $warnings[] = [
                'label' => 'Queue connection is "database", not Redis',
                'help' => 'Works, but Redis has lower overhead for a production queue. Jobs are drained every minute by the cron scheduler even without a worker; a supervised "php artisan queue:work" process just picks them up faster.',
            ];
        }

        return $warnings;
    }

    public function dbDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    public function dbVersion(): string
    {
        try {
            $driver = DB::connection()->getDriverName();
            $result = match ($driver) {
                'pgsql' => DB::selectOne('SELECT version() AS v'),
                'sqlite' => DB::selectOne('SELECT sqlite_version() AS v'),
                default => DB::selectOne('SELECT VERSION() AS v'),
            };

            if ($result === null) {
                return 'unknown';
            }

            /** @var object{v: string} $result */
            $raw = $result->v;

            return match ($driver) {
                'pgsql' => preg_match('/PostgreSQL\s+([\d.]+)/i', $raw, $m) === 1 ? $m[1] : $raw,
                default => $raw,
            };
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    public function cacheStatus(): string
    {
        try {
            Cache::put('magna_health_check', 1, 5);

            return Cache::get('magna_health_check') === 1 ? 'ok' : 'error';
        } catch (Throwable) {
            return 'error';
        }
    }

    /**
     * Read a config value as a string, falling back when it is missing or not
     * a string. `config()` with a default is `mixed`-typed, so this keeps the
     * driver/connection checks type-safe without an unchecked cast.
     */
    private function configString(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }
}
