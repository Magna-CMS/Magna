<?php

declare(strict_types=1);

namespace Magna\Install\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Magna\Auth\Role;
use Magna\Install\ConfigCache;
use Magna\Install\DatabaseInstaller;
use Magna\Install\EnvWriter;
use Magna\Install\Installer;
use Magna\Install\Requirements;
use Magna\Updater\UpdateCheckClient;
use Magna\Users\User;
use Magna\Users\UserStatus;
use Throwable;

class InstallController
{
    public function __construct(
        private readonly Requirements $requirements,
        private readonly EnvWriter $env,
    ) {}

    public function requirements(Request $request): View
    {
        // Generate the install token on first visit (idempotent — returns existing if already generated).
        $installToken = Installer::generateInstallToken();
        $checks = $this->requirements->check($request);

        return view('magna-install::requirements', [
            'step' => 1,
            'checks' => $checks,
            'canContinue' => $this->requirements->requiredPass($checks),
            'installToken' => $installToken,
        ]);
    }

    public function site(Request $request): View|RedirectResponse
    {
        if (! $this->requirements->requiredPass($this->requirements->check($request))) {
            return redirect('/install');
        }

        // Validate the install token passed from the requirements page link.
        // This prevents drive-by visitors from proceeding past step 1.
        $token = $request->string('install_token')->value();
        $storedToken = Installer::getInstallToken();

        if ($storedToken === null || ! hash_equals($storedToken, $token)) {
            return redirect('/install')->withErrors(['token' => 'Invalid install token. Use the Continue button on the requirements page.']);
        }

        // Mark the session so subsequent steps don't re-check the token.
        $request->session()->put('magna_install_verified', true);

        return view('magna-install::site', [
            'step' => 2,
            'defaultUrl' => $request->getSchemeAndHttpHost(),
        ]);
    }

    public function storeSite(Request $request): RedirectResponse
    {
        if (! $request->session()->get('magna_install_verified')) {
            return redirect('/install');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url:http,https', 'max:255'],
            'production' => ['nullable', 'boolean'],
        ]);

        $production = $request->boolean('production');

        $this->env->set([
            'APP_NAME' => $request->string('name')->toString(),
            'APP_URL' => rtrim($request->string('url')->toString(), '/'),
            'APP_ENV' => $production ? 'production' : 'local',
            'APP_DEBUG' => $production ? 'false' : 'true',
        ]);

        $this->clearCachedConfig();

        return redirect('/install/database');
    }

    public function database(): View
    {
        return view('magna-install::database', [
            'step' => 3,
            'defaultSqlitePath' => database_path('database.sqlite'),
        ]);
    }

    public function storeDatabase(Request $request, DatabaseInstaller $installer): RedirectResponse
    {
        if (! $request->session()->get('magna_install_verified')) {
            return redirect('/install');
        }

        $request->validate([
            'driver' => ['required', 'in:sqlite,pgsql,mysql,mariadb'],
            'host' => ['required_unless:driver,sqlite', 'nullable', 'string', 'max:255'],
            'port' => ['required_unless:driver,sqlite', 'nullable', 'integer', 'between:1,65535'],
            'database' => ['required_unless:driver,sqlite', 'nullable', 'string', 'max:255'],
            'username' => ['required_unless:driver,sqlite', 'nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        $driver = $request->string('driver')->toString();
        $connection = $this->connectionConfig($driver, $request);

        try {
            // Probe the connection before committing anything to .env.
            $installer->probe($connection);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['connection' => $this->friendlyDatabaseError($e)]);
        }

        $installer->finalize($this->databaseEnv($driver, $request));

        return redirect('/install/account');
    }

    public function account(): View|RedirectResponse
    {
        if (! Schema::hasTable('users') || Role::query()->where('handle', 'super-admin')->doesntExist()) {
            return redirect('/install/database');
        }

        return view('magna-install::account', ['step' => 4]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        if (! $request->session()->get('magna_install_verified')) {
            return redirect('/install');
        }

        if (! Schema::hasTable('users')) {
            return redirect('/install/database');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = User::query()->create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'status' => UserStatus::Active,
        ]);

        $user->assignRole('super-admin');

        Installer::markInstalled();

        try {
            Artisan::call('storage:link', ['--force' => true]);
        } catch (Throwable) {
            // Symlinks may need elevated rights on some hosts; not fatal.
        }

        // First hub check-in, right after install, so the welcome notice and any
        // live announcements appear immediately instead of waiting up to 12h for
        // the scheduler's first run. Deferred past the response and rescued: it
        // must never delay or fail the final install step if the hub is slow or
        // unreachable.
        app()->terminating(function (): void {
            rescue(fn () => app(UpdateCheckClient::class)->checkIn(), report: false);
        });

        return redirect('/install/complete');
    }

    public function complete(): View|RedirectResponse
    {
        if (! Installer::isInstalled()) {
            return redirect('/install');
        }

        // Gate the success page — it is only meaningful right after installation.
        // After 15 minutes it 404s so it cannot be used to confirm a site's admin URL.
        if (Installer::installedMinutesAgo() > 15) {
            abort(404);
        }

        return view('magna-install::complete', ['step' => 5]);
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(string $driver, Request $request): array
    {
        if ($driver === 'sqlite') {
            $path = $this->sqlitePath($request);

            if (! is_file($path)) {
                @mkdir(dirname($path), 0755, true);
                touch($path);
            }

            return [
                'driver' => 'sqlite',
                'database' => $path,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        $base = [
            'driver' => $driver === 'mariadb' ? 'mariadb' : $driver,
            'host' => $request->string('host')->toString(),
            'port' => $request->integer('port'),
            'database' => $request->string('database')->toString(),
            'username' => $request->string('username')->toString(),
            'password' => $request->string('password')->toString(),
            'prefix' => '',
        ];

        if ($driver === 'pgsql') {
            return $base + ['charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer'];
        }

        return $base + ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true];
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnv(string $driver, Request $request): array
    {
        if ($driver === 'sqlite') {
            return [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $this->sqlitePath($request),
            ];
        }

        return [
            'DB_CONNECTION' => $driver,
            'DB_HOST' => $request->string('host')->toString(),
            'DB_PORT' => (string) $request->integer('port'),
            'DB_DATABASE' => $request->string('database')->toString(),
            'DB_USERNAME' => $request->string('username')->toString(),
            'DB_PASSWORD' => $request->string('password')->toString(),
        ];
    }

    private function sqlitePath(Request $request): string
    {
        $path = $request->string('sqlite_path')->toString();

        if ($path === '') {
            return database_path('database.sqlite');
        }

        // Require a .sqlite extension and ensure the file does not resolve
        // inside public/ (where it would be downloadable) or above the app root.
        if (! str_ends_with($path, '.sqlite')) {
            return database_path('database.sqlite');
        }

        $realDir = realpath(dirname($path)) ?: '';
        $publicPath = rtrim(public_path(), DIRECTORY_SEPARATOR);
        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if ($realDir === '' || str_starts_with($realDir, $publicPath) || ! str_starts_with($realDir.DIRECTORY_SEPARATOR, $basePath)) {
            return database_path('database.sqlite');
        }

        return $path;
    }

    /**
     * A cached configuration would keep serving stale values and silently
     * ignore everything the installer just wrote to .env.
     */
    private function clearCachedConfig(): void
    {
        ConfigCache::clearIfCached();
    }

    private function friendlyDatabaseError(Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'Access denied') || str_contains($message, 'password authentication failed') => 'The database rejected those credentials. Double-check the username and password.',
            str_contains($message, 'Unknown database') || str_contains($message, 'does not exist') => 'That database does not exist yet. Create it on your server first, then try again.',
            str_contains($message, 'Connection refused') || str_contains($message, 'getaddrinfo') || str_contains($message, 'No such host') || str_contains($message, 'timed out') => 'Could not reach the database server. Check the host and port, and that the server is running.',
            default => 'Database connection failed. Check the server settings and try again.',
        };
    }
}
