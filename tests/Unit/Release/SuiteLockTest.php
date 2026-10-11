<?php

declare(strict_types=1);

/**
 * One long run at a time, across every checkout on the machine.
 *
 * Cutting 1.4.8 took four build attempts and about six hours, and three of
 * those hours went to other sessions starting their own suites against the same
 * machine while the release gates ran serially. Each one took roughly a third
 * of the throughput until somebody noticed the process and killed it — which
 * discards work that was not at fault and only happens when a human is
 * watching.
 *
 * So the second run waits. These tests exercise the real script in real
 * subprocesses, because the behaviour under test IS the interaction between two
 * processes; a unit test of the locking function would assert nothing about the
 * thing that was broken.
 *
 * Each case uses its own --name, so a run of this suite never contends with a
 * release build that is genuinely holding the shared lock.
 */

/** The platform's discard sink; "/dev/null" is not a path on Windows. */
function suiteLockNullDevice(): string
{
    return chr(92) === DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null';
}

/** @return array{out: string, code: int, seconds: float} */
function runSuiteLock(string $name, string $innerPhp, int $timeout = 0): array
{
    $script = dirname(__DIR__, 3).'/bin/suite-lock.php';

    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
        .' --name='.$name.' --timeout='.$timeout
        .' -- '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($innerPhp);

    $started = microtime(true);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start suite-lock.');
    }

    $out = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return ['out' => $out, 'code' => $code, 'seconds' => microtime(true) - $started];
}

it('runs the command and hands back its exit code', function (): void {
    $result = runSuiteLock('magnatest-exit', 'echo 123; exit(7);');

    expect($result['out'])->toContain('123')
        ->and($result['code'])->toBe(7);
});

it('makes the second run wait for the first', function (): void {
    $name = 'magnatest-wait';
    $script = dirname(__DIR__, 3).'/bin/suite-lock.php';

    // The holder runs detached, so this test can take the same lock behind it.
    $holder = proc_open(
        escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
            .' --name='.$name.' --timeout=0 -- '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('sleep(4);'),
        [1 => ['file', suiteLockNullDevice(), 'w'], 2 => ['file', suiteLockNullDevice(), 'w']],
        $holderPipes,
    );

    expect($holder)->toBeResource();

    // Long enough for the holder to have taken the lock and stamped it.
    usleep(1_500_000);

    $result = runSuiteLock($name, 'echo 456;');

    if (is_resource($holder)) {
        proc_close($holder);
    }

    expect($result['out'])->toContain('456')
        ->and($result['out'])->toContain('waiting for')
        // It waited for the holder rather than running beside it. The holder
        // sleeps 4s and this starts ~1.5s in, so anything under a second means
        // the lock did nothing.
        ->and($result['seconds'])->toBeGreaterThan(1.0);
});

it('names the holder so a wait never looks like a hang', function (): void {
    $name = 'magnatest-holder';
    $script = dirname(__DIR__, 3).'/bin/suite-lock.php';

    $holder = proc_open(
        escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
            .' --name='.$name.' --timeout=0 -- '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('sleep(3);'),
        [1 => ['file', suiteLockNullDevice(), 'w'], 2 => ['file', suiteLockNullDevice(), 'w']],
        $holderPipes,
    );

    usleep(1_500_000);

    $result = runSuiteLock($name, 'echo 1;');

    if (is_resource($holder)) {
        proc_close($holder);
    }

    // pid, working directory and the command it is running — enough for a
    // waiting session to know whose run to wait for, or to go and ask.
    expect($result['out'])->toMatch('/waiting for pid \d+ in .+: /');
});

it('leaves no holder stamp behind once it is done', function (): void {
    $name = 'magnatest-cleanup';

    runSuiteLock($name, 'echo 1;');

    expect(sys_get_temp_dir().DIRECTORY_SEPARATOR.'magna-'.$name.'.lock.holder')
        ->not->toBeFile();
});

it('refuses to run with nothing after the separator', function (): void {
    $script = dirname(__DIR__, 3).'/bin/suite-lock.php';

    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' --name=magnatest-empty 2>&1', $output, $code);

    expect($code)->toBe(2)
        ->and(implode("\n", $output))->toContain('Nothing to run');
});
