<?php

declare(strict_types=1);

/*
 * Runs before PHPUnit loads a single test, and before Laravel boots.
 *
 * On 13 August 2026 a test run destroyed the development database. The cause
 * was not the tests: `phpunit.xml` sets `DB_DATABASE=:memory:` through <env>,
 * and `config/database.php` reads that with env(). But `php artisan
 * config:cache` had been run, and a cached configuration is a plain PHP array —
 * the config files are never evaluated, so every env() call inside them is dead
 * and the override silently did nothing. The suite connected to the real file
 * and RefreshDatabase dropped every table in it.
 *
 * So the protection cannot live in `phpunit.xml` alone, and it cannot wait for
 * the framework: by the time a test case runs, the connection is already made.
 * Both guards below run here, first, and stop the process rather than warn.
 */

$root = dirname(__DIR__);

/**
 * Refuses to continue, loudly.
 *
 * exit() rather than an exception: an exception inside a bootstrap file can be
 * caught, reported as a failing test and scrolled past. A process that stops is
 * a process that cannot delete anything.
 */
$refuse = static function (string $reason, string $remedy): never {
    fwrite(STDERR, PHP_EOL.str_repeat('=', 78).PHP_EOL);
    fwrite(STDERR, 'REFUSING TO RUN TESTS'.PHP_EOL.PHP_EOL);
    fwrite(STDERR, $reason.PHP_EOL.PHP_EOL);
    fwrite(STDERR, $remedy.PHP_EOL);
    fwrite(STDERR, str_repeat('=', 78).PHP_EOL.PHP_EOL);

    exit(1);
};

/*
 * Guard one: a cached configuration.
 *
 * This is the exact condition that caused the loss. While the cache exists the
 * test environment cannot override anything in a config file, so there is no
 * safe way to run — whatever the database happens to be resolved to today.
 */
if (is_file($root.'/bootstrap/cache/config.php')) {
    $refuse(
        'A cached Laravel configuration is present (bootstrap/cache/config.php).'.PHP_EOL
        .'Cached config is a plain array, so the config files are never evaluated and the'.PHP_EOL
        .'test database settings in phpunit.xml and .env.testing cannot take effect. Tests'.PHP_EOL
        .'would run against whatever database was cached — on this project, the development one.',
        'Run: php artisan config:clear'
    );
}

/*
 * Guard two: name the test database here, above anything a config file says.
 *
 * putenv() and the superglobals together, because Laravel's env() reads through
 * the repository which consults $_ENV and $_SERVER, while some libraries read
 * getenv(). Setting all three means the value is the same wherever it is asked
 * for, and it is set before the framework exists to disagree.
 *
 * `.env.testing` says the same thing and is the file a developer will look for;
 * this is the belt to its braces, and the one that survives someone editing it.
 */
foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require $root.'/vendor/autoload.php';
