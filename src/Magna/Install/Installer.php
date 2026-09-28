<?php

declare(strict_types=1);

namespace Magna\Install;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Magna\MagnaServiceProvider;
use Magna\Updater\Engine\PathGuard;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\Manifest\ReleaseManifest;
use Magna\Updater\UpdatePaths;
use RuntimeException;

final class Installer
{
    public static function isInstalled(): bool
    {
        $override = config('magna.installed_override');

        if ($override !== null) {
            return filter_var($override, FILTER_VALIDATE_BOOL);
        }

        return is_file(self::lockPath());
    }

    /**
     * @param  UpdatePaths|null  $paths  where the install lives; the running application's own paths when omitted
     */
    public static function markInstalled(?UpdatePaths $paths = null): void
    {
        $payload = json_encode([
            'version' => MagnaServiceProvider::VERSION,
            'installed_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT);

        $path = self::lockPath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_put_contents($path, $payload) === false) {
            throw new RuntimeException("Unable to write installation lock at [{$path}].");
        }

        // Destroy the install token now that installation is complete.
        $tokenPath = self::tokenPath();
        if (is_file($tokenPath) && ! unlink($tokenPath)) {
            Log::warning('magna: could not remove install token file after installation.', ['path' => $tokenPath]);
        }

        self::recordFootprint($paths ?? new UpdatePaths(base_path(), storage_path()));
    }

    /**
     * A fresh install is the first delivery: the archive was extracted whole,
     * and its manifest — sitting at the install root — says what that was.
     * Recorded so a later update can tell "delivered by an older updater"
     * from "installed from this release". Never fatal: the install is done.
     */
    private static function recordFootprint(UpdatePaths $paths): void
    {
        try {
            $manifest = ReleaseManifest::fromExtractedArchive($paths->basePath, new PathGuard);

            // Only what actually landed, exactly as the overlay records what it
            // laid down. A core-only archive carries no bundled/magna-cms/plugin-sdk
            // (it is optional, staged only by a hub build), so recording every
            // path the manifest names would enter one the install never received
            // and the footprint check would then report it removed.
            $delivered = $manifest === null ? [] : array_values(array_filter(
                $manifest->coreOwnedPaths,
                static fn (string $relative): bool => is_dir($paths->base($relative)) || is_file($paths->base($relative)),
            ));

            (new InstalledFootprint($paths))->record(
                MagnaServiceProvider::VERSION,
                'fresh',
                null,
                $manifest,
                $delivered,
            );
        } catch (\Throwable $e) {
            Log::warning('magna: could not record the install footprint.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Return the number of whole minutes since installation completed.
     * Returns PHP_INT_MAX if the timestamp cannot be read (e.g., very old lock file without it).
     */
    public static function installedMinutesAgo(): int
    {
        $path = self::lockPath();
        if (! is_file($path)) {
            return PHP_INT_MAX;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return PHP_INT_MAX;
        }

        $data = json_decode($contents, true);
        if (! is_array($data) || ! isset($data['installed_at']) || ! is_string($data['installed_at'])) {
            return PHP_INT_MAX;
        }

        try {
            return (int) now()->diffInMinutes(Carbon::parse($data['installed_at']));
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
    }

    /**
     * Generate (or return existing) pre-install one-time token.
     * The token is written to storage so only someone with filesystem access
     * can read it — this prevents drive-by takeover of uninstalled sites.
     */
    public static function generateInstallToken(): string
    {
        $path = self::tokenPath();

        if (is_file($path)) {
            $existing = file_get_contents($path);
            if (is_string($existing) && strlen($existing) >= 32) {
                return $existing;
            }
        }

        $token = bin2hex(random_bytes(24)); // 48-character hex token

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents($path, $token, LOCK_EX);
        chmod($path, 0600);

        return $token;
    }

    public static function getInstallToken(): ?string
    {
        $path = self::tokenPath();
        if (! is_file($path)) {
            return null;
        }

        $token = file_get_contents($path);

        return (is_string($token) && strlen($token) >= 32) ? $token : null;
    }

    private static function lockPath(): string
    {
        $path = config('magna.install.lock_path');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('magna.install.lock_path is not configured.');
        }

        return $path;
    }

    private static function tokenPath(): string
    {
        return storage_path('app/magna-install-token');
    }
}
