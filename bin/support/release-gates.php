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
    // serial suite. The suite runs last and complete — Unit, Feature and
    // Plugins, never --parallel — with the architecture and duplication
    // ratchets riding inside it.
    $gates = [
        // Not --strict: the exact pins (intervention 3.0, google2fa 8.0) and
        // the SDK's @dev wiring are deliberate, and --strict fails on them.
        // Plain validate still catches broken JSON and a drifted lock.
        'composer validate' => $php.' '.escapeshellarg(release_gates_composer()).' validate --no-check-publish',
        'composer audit' => $php.' '.escapeshellarg(release_gates_composer()).' audit',
        'pint' => $php.' vendor/bin/pint --test',
        'phpstan' => $php.' vendor/bin/phpstan analyse --no-progress --memory-limit=1G',
        'psalm (taint)' => $php.' vendor/bin/psalm --taint-analysis --no-progress',
        'full test suite' => $php.' artisan config:clear --ansi && '.$php.' artisan test',
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
