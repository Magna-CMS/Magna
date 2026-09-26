<?php

declare(strict_types=1);

/**
 * Quality gates for the first-party plugins, run through core's toolchain.
 *
 * Plugins are their own repositories inside plugins-dev/, and for a long
 * time that made them a blind spot: core's Pint scope excludes them, core's
 * phpstan config never pointed at them, and twice a fatal bug lived in a
 * plugin long enough to reach a customer because nothing analysed it. This
 * runner gives every plugin the same three gates core has, using the one
 * vendor/ install core already carries.
 *
 * Usage (from the CMS root — that is where the autoloader and larastan live):
 *   php bin/plugin-gates.php vendor/package    gates for one plugin
 *   php bin/plugin-gates.php --all             gates for every plugin
 *   php bin/plugin-gates.php --arm             arm every plugin repo's hooks
 *
 * Per plugin: `pint --test` with the plugin's own pint.json (run from the
 * plugin directory so its config, not core's, applies), `phpstan` with the
 * plugin's phpstan.neon.dist when it ships one, and its Pest tests through
 * core's suite bootstrap when a tests/ directory exists. A plugin that has
 * not adopted the quality kit yet fails only what it actually has.
 */
const GATE_RESET = "\033[0m";
const GATE_GREEN = "\033[32m";
const GATE_YELLOW = "\033[33m";
const GATE_RED = "\033[31m";

function gate_say(string $message, string $color = GATE_RESET): void
{
    fwrite(STDOUT, $color.$message.GATE_RESET.PHP_EOL);
}

function gate_run(string $command, ?string $cwd = null): bool
{
    $previous = getcwd();

    if ($cwd !== null) {
        chdir($cwd);
    }

    passthru($command, $exitCode);

    if ($cwd !== null && $previous !== false) {
        chdir($previous);
    }

    return $exitCode === 0;
}

/** @return list<string> vendor/package identifiers */
function gate_plugins(string $root): array
{
    $plugins = [];

    foreach (glob($root.'/plugins-dev/*/*/composer.json') ?: [] as $manifest) {
        $dir = dirname($manifest);

        if (str_contains($dir, 'quarantine')) {
            continue;
        }

        // A symlinked plugin is an external product's checkout riding along
        // for development (lekha/core points at its own repository elsewhere
        // on disk). It carries its own toolchain and CI; analysing it through
        // core's stack reports the other product's debt as ours. is_link()
        // misses Windows directory links, so compare resolved paths instead.
        $resolved = str_replace('\\', '/', (string) realpath($dir));
        $expected = str_replace('\\', '/', (string) realpath($root.'/plugins-dev')).'/'.basename(dirname($dir)).'/'.basename($dir);

        if ($resolved !== '' && $resolved !== $expected) {
            gate_say(basename(dirname($dir)).'/'.basename($dir).': external checkout (symlink) — gated by its own repository, skipped', GATE_YELLOW);

            continue;
        }

        $plugins[] = basename(dirname($dir)).'/'.basename($dir);
    }

    sort($plugins);

    return $plugins;
}

/** @return array<string, bool> gate name -> passed */
function gate_plugin(string $root, string $php, string $plugin): array
{
    $dir = $root.'/plugins-dev/'.$plugin;
    $results = [];

    gate_say("== {$plugin} ==", GATE_GREEN);

    if (is_file($dir.'/pint.json')) {
        $results['pint'] = gate_run(escapeshellarg($php).' '.escapeshellarg($root.'/vendor/bin/pint').' --test', $dir);
    } else {
        gate_say('   pint: no pint.json yet (quality kit not adopted) — skipped', GATE_YELLOW);
    }

    if (is_file($dir.'/phpstan.neon.dist')) {
        $results['phpstan'] = gate_run(
            escapeshellarg($php).' vendor/bin/phpstan analyse -c '.escapeshellarg('plugins-dev/'.$plugin.'/phpstan.neon.dist').' --no-progress --memory-limit=1G',
            $root,
        );
    } else {
        gate_say('   phpstan: no phpstan.neon.dist yet (quality kit not adopted) — skipped', GATE_YELLOW);
    }

    if (is_dir($dir.'/tests')) {
        gate_run(escapeshellarg($php).' artisan config:clear --ansi', $root);
        $results['pest'] = gate_run(
            escapeshellarg($php).' vendor/bin/pest '.escapeshellarg('plugins-dev/'.$plugin.'/tests'),
            $root,
        );
    } else {
        gate_say('   pest: no tests/ directory — skipped', GATE_YELLOW);
    }

    return $results;
}

function gate_arm(string $root): int
{
    $failures = 0;

    foreach (gate_plugins($root) as $plugin) {
        $dir = $root.'/plugins-dev/'.$plugin;

        if (! is_dir($dir.'/.git') && ! is_file($dir.'/.git')) {
            continue;
        }

        if (! is_dir($dir.'/.githooks')) {
            gate_say("{$plugin}: no .githooks directory — kit not adopted, skipped", GATE_YELLOW);

            continue;
        }

        exec('git -C '.escapeshellarg($dir).' config core.hooksPath .githooks 2>&1', $output, $exitCode);

        if ($exitCode === 0) {
            gate_say("{$plugin}: hooks armed (.githooks)");
        } else {
            gate_say("{$plugin}: FAILED to arm hooks", GATE_RED);
            $failures++;
        }
    }

    return $failures === 0 ? 0 : 1;
}

$root = str_replace('\\', '/', dirname(__DIR__));
$php = PHP_BINARY;
$argument = $argv[1] ?? null;

if ($argument === null || $argument === '--help') {
    gate_say('Usage: php bin/plugin-gates.php <vendor/package> | --all | --arm');
    exit($argument === null ? 1 : 0);
}

if ($argument === '--arm') {
    exit(gate_arm($root));
}

$targets = $argument === '--all'
    ? gate_plugins($root)
    : [$argument];

$summary = [];
$failed = false;

foreach ($targets as $plugin) {
    if (! is_dir($root.'/plugins-dev/'.$plugin)) {
        gate_say("No such plugin: plugins-dev/{$plugin}", GATE_RED);
        exit(1);
    }

    $results = gate_plugin($root, $php, $plugin);
    $summary[$plugin] = $results;

    foreach ($results as $passed) {
        if (! $passed) {
            $failed = true;
        }
    }
}

gate_say('');
gate_say('== summary ==', GATE_GREEN);

foreach ($summary as $plugin => $results) {
    $parts = [];

    foreach ($results as $gate => $passed) {
        $parts[] = ($passed ? GATE_GREEN.'✓' : GATE_RED.'✗').' '.$gate.GATE_RESET;
    }

    gate_say(sprintf('   %-32s %s', $plugin, $parts === [] ? GATE_YELLOW.'(no gates yet)'.GATE_RESET : implode('  ', $parts)));
}

exit($failed ? 1 : 0);
