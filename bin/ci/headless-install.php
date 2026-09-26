<?php

declare(strict_types=1);

/**
 * Install an extracted Magna release without the web installer.
 *
 * For the upgrade matrix: CI extracts a PREVIOUS release into a directory and
 * needs it installed — .env written, database migrated and seeded, install
 * lock in place — so the current build can be applied over it by that
 * release's own updater. Era-tolerant on purpose: nothing here calls a Magna
 * class, because the release being installed may predate every one of them.
 * Files are written directly and the release's own artisan does the rest in
 * its own process.
 *
 * Usage:
 *   php bin/ci/headless-install.php <install-dir>
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/ci/headless-install.php <install-dir>\n");
    exit(2);
}

$install = rtrim($argv[1], '/\\');

if (! is_file($install.'/artisan') || ! is_file($install.'/.env.example')) {
    fwrite(STDERR, "{$install} does not look like an extracted Magna release (no artisan or .env.example).\n");
    exit(2);
}

$database = $install.'/database/database.sqlite';
@mkdir(dirname($database), 0755, true);
touch($database);

$env = [
    'APP_NAME' => 'Magna',
    'APP_ENV' => 'local',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_DEBUG' => 'false',
    'APP_URL' => 'http://localhost',
    'LOG_CHANNEL' => 'single',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database,
    // Both spellings: older releases read CACHE_DRIVER, Laravel 11+ CACHE_STORE.
    'CACHE_DRIVER' => 'file',
    'CACHE_STORE' => 'file',
    'SESSION_DRIVER' => 'file',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'log',
    // The archive under test is built by CI and carries no hub signature.
    // Both keys: the one before the 1.4.0 rename and the one after it.
    'MAGNA_UPDATER_REQUIRE_SIGNED_CHECKSUM' => 'false',
    'MAGNA_UPDATER_ALLOW_UNSIGNED_CHECKSUM' => 'true',
    'MAGNA_PASSWORD_CHECK_COMPROMISED' => 'false',
];

$lines = [];
foreach ($env as $key => $value) {
    $lines[] = $key.'='.$value;
}
file_put_contents($install.'/.env', implode("\n", $lines)."\n");
say('.env written');

run($install, ['migrate', '--force', '--no-interaction']);

// The role seeder has been Database\Seeders\RoleSeeder since v1.2.0.
run($install, ['db:seed', '--class=Database\\Seeders\\RoleSeeder', '--force', '--no-interaction']);

$lock = $install.'/storage/app/magna-installed.json';
@mkdir(dirname($lock), 0755, true);
file_put_contents($lock, (string) json_encode([
    'version' => installed_version($install),
    'installed_at' => date('c'),
], JSON_PRETTY_PRINT));
say('install lock written: '.$lock);

say('Installed '.installed_version($install).' at '.$install);
exit(0);

function run(string $install, array $arguments): void
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($install.'/artisan').' '.implode(' ', array_map('escapeshellarg', $arguments));
    say('> php artisan '.implode(' ', $arguments));
    passthru($command, $code);

    if ($code !== 0) {
        fwrite(STDERR, 'artisan '.implode(' ', $arguments)." failed with exit code {$code}.\n");
        exit(1);
    }
}

function installed_version(string $install): string
{
    $source = (string) @file_get_contents($install.'/src/Magna/MagnaServiceProvider.php');

    return preg_match('/const\s+VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $m) === 1 ? $m[1] : 'unknown';
}

function say(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}
