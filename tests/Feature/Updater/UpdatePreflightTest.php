<?php

declare(strict_types=1);

use Magna\MagnaServiceProvider;
use Magna\Updater\CoreUpdater;
use Magna\Updater\Preflight\PreflightProblem;
use Magna\Updater\Preflight\UpdatePreflight;
use Magna\Updater\UpdateMode;
use Magna\Updater\UpdatePaths;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Everything that must be true before a core update writes a byte, answered
 * as problems the admin can act on rather than as exceptions.
 */
function preflightInstall(bool $writable = true): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-preflight-'.bin2hex(random_bytes(6));

    foreach (CoreUpdater::coreOwnedPaths() as $relative) {
        mkdir($base.'/'.$relative, 0777, true);
        file_put_contents($base.'/'.$relative.'/Sample.php', '<?php // sample');
    }
    mkdir($base.'/storage/app', 0777, true);

    if (! $writable) {
        chmod($base.'/src/Magna/Sample.php', 0444);
    }

    return new UpdatePaths($base, $base.'/storage');
}

function removePreflightInstall(UpdatePaths $paths): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($paths->basePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        @chmod($item->getPathname(), 0777);
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($paths->basePath);
}

/** @param  list<PreflightProblem>  $problems */
function preflightCodes(array $problems): array
{
    return array_map(static fn (PreflightProblem $p): string => $p->code, $problems);
}

it('reports nothing for a writable install with room to spare', function (): void {
    $paths = preflightInstall();

    try {
        $problems = (new UpdatePreflight($paths))->check('99.0.0', UpdateMode::Update);

        expect($problems)->toBe([]);
    } finally {
        removePreflightInstall($paths);
    }
});

/*
 * The guard that matters most. CoreUpdateJob skips a release the install
 * already runs, but the stalled-worker fallback calls apply() straight from
 * the admin's poll and never passed through it — so a release the hub
 * re-announced, or an older one it was made to announce, would have been
 * re-applied. A downgrade is a way to reinstate a fixed vulnerability.
 */
it('refuses a target at or below the running version in update mode', function (): void {
    $paths = preflightInstall();
    $preflight = new UpdatePreflight($paths);

    try {
        $same = $preflight->check(MagnaServiceProvider::VERSION, UpdateMode::Update);
        $lower = $preflight->check('1.0.0', UpdateMode::Update);
        $prefixed = $preflight->check('v'.MagnaServiceProvider::VERSION, UpdateMode::Update);

        expect(preflightCodes($same))->toBe(['downgrade'])
            ->and(preflightCodes($lower))->toBe(['downgrade'])
            ->and(preflightCodes($prefixed))->toBe(['downgrade'])
            ->and($preflight->firstBlocking($same)?->message)->toContain('not newer than the installed');
    } finally {
        removePreflightInstall($paths);
    }
});

it('allows only the installed version in repair mode', function (): void {
    $paths = preflightInstall();
    $preflight = new UpdatePreflight($paths);

    try {
        expect($preflight->check(MagnaServiceProvider::VERSION, UpdateMode::Repair))->toBe([])
            ->and(preflightCodes($preflight->check('99.0.0', UpdateMode::Repair)))->toBe(['repair_version']);
    } finally {
        removePreflightInstall($paths);
    }
});

it('refuses a target that is not a version number', function (): void {
    $paths = preflightInstall();

    try {
        $problems = (new UpdatePreflight($paths))->check('latest', UpdateMode::Update);

        expect(preflightCodes($problems))->toBe(['malformed_version']);
    } finally {
        removePreflightInstall($paths);
    }
});

it('refuses when the disk cannot hold the archive, its extraction and the backup', function (): void {
    $paths = preflightInstall();

    try {
        // A petabyte archive: no test machine has three of those free.
        $problems = (new UpdatePreflight($paths))->check('99.0.0', UpdateMode::Update, archiveBytes: 1_000_000_000_000_000);

        expect(preflightCodes($problems))->toBe(['disk_space'])
            ->and($problems[0]->message)->toContain('MB is free');
    } finally {
        removePreflightInstall($paths);
    }
});

it('reports unwritable core files as a blocking problem with the remedy', function (): void {
    $paths = preflightInstall(writable: false);

    try {
        $problems = (new UpdatePreflight($paths))->check('99.0.0', UpdateMode::Update);

        expect(preflightCodes($problems))->toBe(['unwritable'])
            ->and($problems[0]->message)->toContain('src/Magna/Sample.php')
            ->and($problems[0]->message)->toContain('nothing was changed');
    } finally {
        removePreflightInstall($paths);
    }
});

/*
 * What Update Manager said the latest release needs, refused before the
 * download it would otherwise take to find out. Hints only: the archive's
 * manifest is enforced regardless once it is on disk.
 */
it('refuses ahead of the download when the server says the release needs a newer php', function (): void {
    $paths = preflightInstall();

    try {
        $problems = (new UpdatePreflight($paths))->hintProblems('>=8.4.0', null, phpVersion: '8.3.12');

        expect(preflightCodes($problems))->toBe(['php_floor'])
            ->and($problems[0]->message)->toContain('needs PHP >=8.4.0');

        expect((new UpdatePreflight($paths))->hintProblems('>=8.3.0', null, phpVersion: '8.3.12'))->toBe([]);
    } finally {
        removePreflightInstall($paths);
    }
});

it('refuses ahead of the download when the site is older than the release can be applied over', function (): void {
    $paths = preflightInstall();

    try {
        $problems = (new UpdatePreflight($paths))->hintProblems(null, '99.0.0');

        expect(preflightCodes($problems))->toBe(['min_upgrade_from'])
            ->and($problems[0]->message)->toContain('v99.0.0 or newer');

        expect((new UpdatePreflight($paths))->hintProblems(null, '1.2.0'))->toBe([]);
    } finally {
        removePreflightInstall($paths);
    }
});

it('does not hold a php hint it cannot parse against the release', function (): void {
    $paths = preflightInstall();

    try {
        expect((new UpdatePreflight($paths))->hintProblems('not a constraint', null, phpVersion: '8.3.12'))->toBe([]);
    } finally {
        removePreflightInstall($paths);
    }
});
