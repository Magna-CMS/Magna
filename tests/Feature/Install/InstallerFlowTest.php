<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Magna\Install\DatabaseInstaller;
use Magna\Install\EnvWriter;
use Magna\Users\User;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/magna-install-'.uniqid();
    File::makeDirectory($this->dir, 0755, true);

    config([
        'magna.installed_override' => null,
        'magna.install.lock_path' => $this->dir.'/installed.json',
        'magna.install.env_path' => $this->dir.'/.env',
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
    // Clean up the SQLite file created by the happy-path test (inside base_path).
    $sqlite = database_path('magna-test-install.sqlite');
    if (file_exists($sqlite)) {
        @unlink($sqlite);
    }
});

it('redirects all web traffic to the installer until installed', function (): void {
    $this->get('/')->assertRedirect('/install');
});

it('returns 404 for installer routes once installed', function (): void {
    config(['magna.installed_override' => true]);

    $this->get('/install')->assertNotFound();
    $this->post('/install/site', [])->assertNotFound();
});

it('shows the requirements checklist', function (): void {
    $this->get('/install')
        ->assertOk()
        ->assertSee('Welcome to Magna')
        ->assertSee('PHP 8.3 or newer');
});

it('validates the site step', function (): void {
    $this->withSession(['magna_install_verified' => true])
        ->from('/install/site')
        ->post('/install/site', ['name' => '', 'url' => 'not-a-url'])
        ->assertRedirect('/install/site')
        ->assertSessionHasErrors(['name', 'url']);
});

it('rejects unreachable database servers with a friendly error', function (): void {
    $this->withSession(['magna_install_verified' => true])
        ->from('/install/database')
        ->post('/install/database', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nope',
            'username' => 'nope',
            'password' => '',
        ])
        ->assertRedirect('/install/database')
        ->assertSessionHasErrors('connection');
});

it('names the missing PHP extension when PDO has no driver for the chosen type', function (): void {
    // A PostgreSQL install on a host without php-pgsql threw
    // "could not find driver", which fell through to the catch-all
    // "check the server settings" — pointing at the host, port and password
    // when the credentials were fine and only the PHP extension was absent.
    $this->instance(DatabaseInstaller::class, new class(app(EnvWriter::class)) extends DatabaseInstaller
    {
        public function probe(array $connection): void
        {
            throw new PDOException('could not find driver');
        }
    });

    $this->withSession(['magna_install_verified' => true])
        ->from('/install/database')
        ->post('/install/database', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'magna',
            'username' => 'magna',
            'password' => 'secret',
        ])
        ->assertRedirect('/install/database')
        ->assertSessionHasErrors(['connection' => 'PHP has no driver for that database type. Install the matching extension (php-pgsql, php-mysql) and try again.']);
});

it('completes the full installation happy path', function (): void {
    // Step 2 — site details are written straight to .env.
    $this->withSession(['magna_install_verified' => true])
        ->post('/install/site', [
            'name' => 'My Magna Site',
            'url' => 'https://example.com/',
            'production' => '1',
        ])->assertRedirect('/install/database');

    $env = (string) file_get_contents($this->dir.'/.env');
    expect($env)->toContain('APP_NAME="My Magna Site"')
        ->and($env)->toContain('APP_URL=https://example.com')
        ->and($env)->toContain('APP_ENV=production')
        ->and($env)->toContain('APP_DEBUG=false');

    // Step 3 — SQLite database: connection probed, migrated, seeded.
    // SQLite path must be inside base_path() (installer security guard).
    // (magna_install_verified is normally set by GET /install/site; supply it here to skip that step)
    $sqlitePath = database_path('magna-test-install.sqlite');
    $this->withSession(['magna_install_verified' => true])
        ->post('/install/database', [
            'driver' => 'sqlite',
            'sqlite_path' => $sqlitePath,
        ])->assertRedirect('/install/account');

    expect(file_exists($sqlitePath))->toBeTrue()
        ->and((string) file_get_contents($this->dir.'/.env'))->toContain('DB_CONNECTION=sqlite');

    // Step 4 — admin account gets super-admin, install locks.
    $this->post('/install/account', [
        'name' => 'Ada Admin',
        'email' => 'ada@example.com',
        'password' => 'a-very-long-password',
        'password_confirmation' => 'a-very-long-password',
    ])->assertRedirect('/install/complete');

    expect(file_exists($this->dir.'/installed.json'))->toBeTrue();

    $admin = User::query()->where('email', 'ada@example.com')->firstOrFail();
    expect($admin->isSuperAdmin())->toBeTrue();

    // Aftermath: success page renders, site is live (root now redirects
    // guests to the panel login), installer is gone.
    $this->get('/install/complete')->assertOk()->assertSee('Magna is installed');
    $this->get('/')->assertRedirect('/login');
    $this->get('/install')->assertNotFound();
});

it('guards the account step until the database is ready', function (): void {
    // Roles are not seeded in the test database, so the guard must bounce.
    $this->get('/install/account')->assertRedirect('/install/database');
});

// ── A-01: install token null-bypass is fixed ──────────────────────────────────

it('install site step rejects any token when no token file exists', function (): void {
    // Ensure no token file exists — Installer::getInstallToken() returns null
    $tokenFile = storage_path('app/magna-install-token');
    @unlink($tokenFile);

    // Old null-bypass: ($storedToken !== null && !hash_equals(...)) evaluated
    // to false when storedToken was null — attacker slipped through.
    // Fixed: ($storedToken === null || !hash_equals(...)) now correctly rejects.
    $this->get('/install/site?install_token=any_arbitrary_token')
        ->assertRedirect('/install');
});

// ── A-02: storeSite requires magna_install_verified session flag ──────────────

it('storeSite rejects POST without magna_install_verified session flag', function (): void {
    // Without the session guard an attacker could directly POST /install/site
    // and overwrite .env without going through the token-verified requirements page.
    $this->post('/install/site', [
        'name' => 'Attacker Site',
        'url' => 'https://attacker.example.com',
    ])->assertRedirect('/install');
});
