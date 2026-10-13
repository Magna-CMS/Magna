<?php

declare(strict_types=1);

/**
 * Runs one long command at a time across every checkout on this machine.
 *
 * Usage:
 *   php bin/suite-lock.php -- php artisan test
 *   php bin/suite-lock.php --timeout=0 -- php bin/build-release.php 1.4.9
 *   php bin/suite-lock.php --name=release -- ...
 *
 * Why this exists. Cutting 1.4.8 took four build attempts and about six hours,
 * and most of the elapsed time was not the release: three other sessions each
 * started a full suite against the same machine while the release gates were
 * running serially, and each one took roughly a third of the throughput until
 * somebody noticed the process and killed it. Killing is the wrong answer — it
 * throws away work that was not at fault, and it only happens if a human is
 * watching.
 *
 * So the second run waits instead. It is not a mutex over the repository (the
 * gates read the working tree, and nothing here changes that); it is a mutex
 * over "a heavy run is using this machine right now".
 *
 * Deliberately in the SYSTEM temp directory rather than in the checkout: the
 * contention is between checkouts — a worktree analysing while the main tree
 * builds — so a per-checkout lock would be a lock over nothing. That is the
 * opposite of the analyser caches, which moved INTO the checkout for exactly
 * the same reason read the other way round (see phpstan.neon.dist tmpDir).
 *
 * The holder writes who it is into a sidecar file beside the lock, so a waiter
 * can say what it is waiting for instead of looking hung. Beside, not inside:
 * on Windows a file held under an exclusive flock cannot be read by another
 * process at all, and reading it to name the holder produced
 * "stream_get_contents(): Read of 8192 bytes failed with errno=13 Permission
 * denied". The lock file is therefore only ever locked, never read.
 *
 * The lock is advisory and released by the operating system when the process
 * ends, so a killed or crashed holder does not wedge the machine. The sidecar
 * can outlive a killed holder, which is why it is only ever used to describe
 * what a waiter is waiting for and never to decide whether to wait.
 *
 * Not a gate. It never fails a build on its own: a lock it cannot take within
 * the timeout is reported and the command runs anyway, because a release
 * refused because another session was busy would be a worse failure than a
 * slow one.
 */
const SUITE_LOCK_DEFAULT_TIMEOUT = 7200;

$argv = $_SERVER['argv'] ?? [];
array_shift($argv);

$timeout = SUITE_LOCK_DEFAULT_TIMEOUT;
$name = 'suite';
$command = [];
$separatorSeen = false;

foreach ($argv as $arg) {
    if ($separatorSeen) {
        $command[] = $arg;

        continue;
    }

    if ($arg === '--') {
        $separatorSeen = true;

        continue;
    }

    if (str_starts_with($arg, '--timeout=')) {
        $timeout = max(0, (int) substr($arg, 10));

        continue;
    }

    if (str_starts_with($arg, '--name=')) {
        $candidate = substr($arg, 7);
        // One path segment, so the name can never climb out of the temp
        // directory or collide with something unrelated in it.
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', $candidate) ?: 'suite';

        continue;
    }

    suite_lock_fail("Unknown option: {$arg}");
}

if ($command === []) {
    suite_lock_fail('Nothing to run. Put the command after `--`, e.g. php bin/suite-lock.php -- php artisan test');
}

$lockPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'magna-'.$name.'.lock';
$holderPath = $lockPath.'.holder';

$handle = fopen($lockPath, 'c+');

if ($handle === false) {
    // Never fatal: a machine where the lock cannot be opened should still be
    // able to run its tests.
    fwrite(STDERR, "suite-lock: cannot open {$lockPath}; running without a lock\n");

    exit(suite_lock_run($command));
}

$waitedFrom = time();
$announced = false;
$held = false;

while (true) {
    if (flock($handle, LOCK_EX | LOCK_NB)) {
        $held = true;

        break;
    }

    if (! $announced) {
        fwrite(STDERR, 'suite-lock: waiting for '.suite_lock_holder($holderPath)."\n");
        $announced = true;
    }

    if ($timeout > 0 && (time() - $waitedFrom) >= $timeout) {
        fwrite(STDERR, "suite-lock: still locked after {$timeout}s; running anyway\n");

        break;
    }

    sleep(2);
}

if ($held) {
    @file_put_contents($holderPath, json_encode([
        'pid' => getmypid(),
        'cwd' => getcwd(),
        'command' => implode(' ', $command),
        'since' => date('c'),
    ], JSON_THROW_ON_ERROR).PHP_EOL);

    if ($announced) {
        fwrite(STDERR, 'suite-lock: acquired after '.(time() - $waitedFrom)."s\n");
    }
}

$exitCode = suite_lock_run($command);

if ($held) {
    // Removed before releasing, so the next waiter's "waiting for" line never
    // quotes a process that has already finished.
    @unlink($holderPath);
    flock($handle, LOCK_UN);
}

fclose($handle);

exit($exitCode);

/**
 * @param  list<string>  $command
 */
function suite_lock_run(array $command): int
{
    // Quote anything outside a known-safe charset, not just whitespace: the
    // shell behind passthru() is cmd on Windows but sh on Linux, where a bare
    // `(`, `;` or `$` is syntax, not data — `php -r sleep(4);` dies on sh
    // before php ever runs. `%` stays bare so Windows env expansion keeps
    // working (escapeshellarg would blank it there).
    $line = implode(' ', array_map(
        static fn (string $part): string => preg_match('#^[A-Za-z0-9_\-./:\\\\=%+@,]+$#', $part) === 1 ? $part : escapeshellarg($part),
        $command,
    ));

    passthru($line, $exitCode);

    return $exitCode;
}

/**
 * Who holds the lock, as far as the sidecar says — "another run" when there is
 * no sidecar yet, which is the window between taking the lock and stamping it,
 * and also what a holder that was killed leaves behind.
 */
function suite_lock_holder(string $holderPath): string
{
    $raw = @file_get_contents($holderPath);

    if (! is_string($raw) || trim($raw) === '') {
        return 'another run';
    }

    $decoded = json_decode(trim($raw), true);

    if (! is_array($decoded)) {
        return 'another run';
    }

    $pid = is_scalar($decoded['pid'] ?? null) ? (string) $decoded['pid'] : '?';
    $command = is_string($decoded['command'] ?? null) ? $decoded['command'] : 'a command';
    $since = is_string($decoded['since'] ?? null) ? $decoded['since'] : 'an unknown time';
    $cwd = is_string($decoded['cwd'] ?? null) ? $decoded['cwd'] : '?';

    return "pid {$pid} in {$cwd} since {$since}: {$command}";
}

function suite_lock_fail(string $message): never
{
    fwrite(STDERR, "suite-lock: {$message}\n");

    exit(2);
}
