<?php

declare(strict_types=1);

/**
 * A workflow may not claim a database driver the suite cannot run on.
 *
 * `tests/bootstrap.php` sets DB_CONNECTION=sqlite and DB_DATABASE=:memory:
 * into putenv(), $_ENV and $_SERVER, unconditionally, before
 * vendor/autoload.php is required. Nothing a workflow exports — $GITHUB_ENV, a
 * step `env:`, a .env line — can reach the suite past that.
 *
 * So for a year the step named "Run test suite (Postgres)" ran on SQLite, and
 * `ci.yml` varied a `db: [sqlite, pgsql, mysql]` axis across six jobs that were
 * all the same job. The Postgres and MySQL containers were started for every
 * one of them and used by nothing, and `ci.yml` carried a comment asserting
 * that its export "is what actually switches the driver the tests run on".
 * Every driver-specific defect — dialect, column type, transaction semantics —
 * passed every gate in the project, and would have gone out in a release.
 *
 * The claim is now gone rather than the override. This test is what keeps the
 * two from drifting apart again: the day someone adds the escape hatch and
 * genuinely runs Postgres, this test fails and says so, which is the right
 * moment to delete it. While the override stands, a workflow that names a
 * driver is lying, and a lying gate is worse than a missing one.
 */

/** @return array<string, string> job name => the job's own YAML block */
function workflowJobs(string $path): array
{
    $lines = explode("\n", (string) file_get_contents($path));
    $jobs = [];
    $current = null;

    foreach ($lines as $line) {
        // Two spaces then a key: a job. Anything deeper belongs to it.
        if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $match) === 1) {
            $current = $match[1];
            $jobs[$current] = '';

            continue;
        }

        if ($current !== null) {
            $jobs[$current] .= $line."\n";
        }
    }

    return $jobs;
}

/** @return list<string> */
function workflowPaths(): array
{
    $found = glob(dirname(__DIR__, 3).'/.github/workflows/*.yml');

    return $found === false ? [] : $found;
}

it('finds the workflows it is meant to be reading', function (): void {
    // Otherwise every assertion below passes over an empty list.
    expect(workflowPaths())->not->toBeEmpty();

    $names = array_map(static fn (string $path): string => basename($path), workflowPaths());

    expect($names)->toContain('ci.yml')->toContain('release.yml');
});

it('keeps the sqlite override the workflows are honest about', function (): void {
    $bootstrap = (string) file_get_contents(dirname(__DIR__, 3).'/tests/bootstrap.php');

    expect($bootstrap)->toContain("'DB_CONNECTION' => 'sqlite'")
        ->toContain("'DB_DATABASE' => ':memory:'");
});

it('never names another driver on a job that runs the suite', function (): void {
    foreach (workflowPaths() as $path) {
        foreach (workflowJobs($path) as $job => $body) {
            if (! str_contains($body, 'artisan test')) {
                continue;
            }

            $where = basename($path).' job "'.$job.'"';

            // A container nothing can reach is CI minutes spent to make a
            // claim; octane-smoke keeps its Postgres because it migrates
            // against it for real, and it runs no suite.
            expect($body)
                ->not->toContain('image: postgres', $where.' starts Postgres for a suite that cannot use it.')
                ->not->toContain('image: mysql', $where.' starts MySQL for a suite that cannot use it.')
                ->not->toContain('DB_CONNECTION=pgsql', $where.' exports a driver the bootstrap overrides.')
                ->not->toContain('DB_CONNECTION=mysql', $where.' exports a driver the bootstrap overrides.')
                ->not->toContain('DB_CONNECTION: pgsql', $where.' sets a driver the bootstrap overrides.')
                ->not->toContain('DB_CONNECTION: mysql', $where.' sets a driver the bootstrap overrides.');

            // The step names are what a human reads in the checks list, and
            // "Run test suite (Postgres)" is what made this survive two
            // releases.
            foreach (['Postgres', 'PostgreSQL', 'MySQL', 'MariaDB', 'pgsql', 'mysql'] as $driver) {
                expect($body)->not->toMatch(
                    '/^\s*- name:.*'.preg_quote($driver, '/').'/mi',
                    $where.' has a step named after '.$driver.', which the suite does not run on.',
                );
            }
        }
    }
});

it('does not vary a database axis that cannot vary', function (): void {
    foreach (workflowPaths() as $path) {
        expect((string) file_get_contents($path))->not->toContain(
            'matrix.db',
            basename($path).' still varies a db axis; every row of it runs SQLite.',
        );
    }
});
