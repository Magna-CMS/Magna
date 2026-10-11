<?php

declare(strict_types=1);

use Magna\Support\Runtime;

/**
 * The inline update/install fallbacks ask for a long time limit because a web
 * request only allows 30s. set_time_limit() grants it, but it also RESETS the
 * limit — so on a process that had none, asking for more imposes a cap.
 *
 * That is not hypothetical. Under CLI (max_execution_time=0) a single test
 * touching CoreUpdateStarter capped the whole process at 1800s, and the serial
 * release suite then died mid-run at "Maximum execution time of 1800 seconds
 * exceeded" in an unrelated database call, with no php.ini setting to blame.
 *
 * Each case restores whatever the runner was given, so the suite's own limit
 * survives these tests.
 */
function withTimeLimit(string $limit, callable $case): void
{
    $original = ini_get('max_execution_time');

    try {
        ini_set('max_execution_time', $limit);
        $case();
    } finally {
        ini_set('max_execution_time', $original === false ? '0' : $original);
    }
}

it('leaves an unlimited process unlimited', function (): void {
    withTimeLimit('0', function (): void {
        Runtime::extendTimeLimit(1800);

        expect(ini_get('max_execution_time'))->toBe('0');
    });
});

it('raises a short limit to what the caller needs', function (): void {
    withTimeLimit('30', function (): void {
        Runtime::extendTimeLimit(1800);

        expect((int) ini_get('max_execution_time'))->toBe(1800);
    });
});

it('leaves a longer limit alone', function (): void {
    withTimeLimit('3600', function (): void {
        Runtime::extendTimeLimit(1800);

        expect((int) ini_get('max_execution_time'))->toBe(3600);
    });
});
