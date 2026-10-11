<?php

declare(strict_types=1);

/**
 * The quality gates a release build must pass before a single file is staged.
 *
 * Releases are built locally by `php bin/build-release.php`, and for a long
 * time that path ran ZERO quality gates — the tag-triggered workflow tested,
 * but a local build could zip and ship whatever the working tree held.
 * 1.4.1 shipped that way with a defect CI would have caught. This file is
 * the missing chokepoint: every check `composer check` and CI agree on, run
 * cheapest-first and fail-fast, before the builder is allowed to stage.
 *
 * Standalone:  php bin/support/release-gates.php
 * Included:    require this file, then release_gates_run($skip)
 *
 * `--skip-gates` exists for the one legitimate emergency (shipping a hotfix
 * while an unrelated gate is red) and announces itself in red; it is never
 * the routine path, and the tag pipeline still runs everything.
 */
function release_gates_run(bool $skip): void
{
    $red = "\033[31m";
    $green = "\033[32m";
    $reset = "\033[0m";

    if ($skip) {
        fwrite(STDOUT, $red.str_repeat('!', 72).PHP_EOL);
        fwrite(STDOUT, '!!  --skip-gates: THIS RELEASE SHIPS UNVERIFIED.'.PHP_EOL);
        fwrite(STDOUT, '!!  No pint, no phpstan, no psalm, no tests, no audit stand behind it.'.PHP_EOL);
        fwrite(STDOUT, str_repeat('!', 72).$reset.PHP_EOL);

        return;
    }

    $php = escapeshellarg(PHP_BINARY);

    // Cheapest first, so a trivial failure costs seconds, not the full
    // serial suite. Never --parallel: Laravel's parallel testing separates
    // workers by appending TEST_TOKEN to the database name, which a
    // DB_DATABASE of ":memory:" cannot express (tests/bootstrap.php asserts
    // that literal in every worker before autoload), and the backup and
    // content-type suites share filesystem and registry state besides.
    //
    // Two suite gates, not one. The second is the complete answer and the
    // thing that may refuse a build; the first exists because the second
    // takes hours, and four 1.4.7 builds in a row were refused by a core
    // defect that the first would have surfaced in minutes. Both run under
    // bin/suite-lock.php, so a session that starts its own suite meanwhile
    // waits its turn instead of halving this one's throughput — which cost
    // that release more elapsed time than any of its actual defects.
    // Bounded, not indefinite. A release can afford to queue behind somebody
    // else's suite — thrashing two of them helps nobody — but "wait forever"
    // is how an unattended build spends a night holding a slot it never gets.
    // Half an hour outlasts any suite on this machine; past that it runs and
    // takes the contention, which is strictly better than not building.
    $lock = $php.' '.escapeshellarg(dirname(__DIR__).'/suite-lock.php').' --name=suite --timeout=1800 --';
    $gates = [
        // Not --strict: the exact pins (intervention 3.0, google2fa 8.0) and
        // the SDK's @dev wiring are deliberate, and --strict fails on them.
        // Plain validate still catches broken JSON and a drifted lock.
        'composer validate' => $php.' '.escapeshellarg(release_gates_composer()).' validate --no-check-publish',
        'composer audit' => $php.' '.escapeshellarg(release_gates_composer()).' audit',
        'pint' => $php.' vendor/bin/pint --test',
        'phpstan' => $php.' vendor/bin/phpstan analyse --no-progress --memory-limit=1G',
        'psalm (taint)' => $php.' vendor/bin/psalm --taint-analysis --no-progress',
        'core suite (fast)' => $lock.' '.$php.' artisan test --testsuite=Unit,Feature',
        // Scoped to what a core release can answer for. The Plugins glob in
        // phpunit.xml takes everything under plugins-dev, which on a developer
        // machine includes client products: a 1.4.8 build was refused by 660
        // failures that were all `lekha_test` on a Postgres server that was not
        // running. A core release is not the place to discover that. The glob
        // stays for `composer test`; FirstPartyPlugins names the plugins core
        // ships alongside and therefore owes a passing suite to.
        'full test suite' => $php.' artisan config:clear --ansi && '.$lock.' '.$php.' artisan test --testsuite=Unit,Feature,FirstPartyPlugins',
    ];

    foreach ($gates as $name => $command) {
        fwrite(STDOUT, $green."release gates: {$name}".$reset.PHP_EOL);
        passthru($command, $exitCode);

        if ($exitCode !== 0) {
            fwrite(STDOUT, $red."release gates: {$name} FAILED — refusing to build. Fix it, or ship an emergency with --skip-gates (and answer for it).".$reset.PHP_EOL);
            exit(1);
        }
    }

    fwrite(STDOUT, $green.'release gates: all green.'.$reset.PHP_EOL);
}

/** The same composer resolution order the builder itself uses. */
function release_gates_composer(): string
{
    $composer = getenv('COMPOSER_BIN');

    if (is_string($composer) && $composer !== '') {
        return $composer;
    }

    foreach ([
        getenv('HOME').'/.config/herd/bin/composer.phar',
        getenv('USERPROFILE').'/.config/herd/bin/composer.phar',
    ] as $candidate) {
        if (is_string($candidate) && is_file($candidate)) {
            return $candidate;
        }
    }

    return 'composer';
}

// Standalone invocation: run immediately.
if (isset($argv) && realpath($argv[0] ?? '') === __FILE__) {
    chdir(dirname(__DIR__, 2));
    release_gates_run(in_array('--skip-gates', $argv, true));
}
