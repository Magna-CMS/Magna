<?php

declare(strict_types=1);

use Magna\Updater\UpdateHousekeeping;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Every update left a full snapshot behind forever and every failed one left
 * its download and extraction in tmp/. On a shared host that is a full disk,
 * and a full disk is a site that stops writing sessions.
 */
function housekeepingPaths(): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-housekeeping-'.bin2hex(random_bytes(6));
    mkdir($base.'/storage', 0777, true);

    return new UpdatePaths($base, $base.'/storage');
}

function removeHousekeepingPaths(UpdatePaths $paths): void
{
    (new Filesystem)->remove($paths->basePath);
}

it('keeps only the newest snapshots', function (): void {
    $paths = housekeepingPaths();

    foreach (['2026_01_01_000000', '2026_03_01_000000', '2026_02_01_000000', '2026_04_01_000000'] as $name) {
        mkdir($paths->backupsDir().'/'.$name, 0777, true);
        file_put_contents($paths->backupsDir().'/'.$name.'/sample.php', '<?php');
    }

    try {
        $removed = (new UpdateHousekeeping(new Filesystem, $paths))->pruneBackups(keep: 2);

        expect($removed)->toBe(['2026_02_01_000000', '2026_01_01_000000'])
            ->and(is_dir($paths->backupsDir().'/2026_04_01_000000'))->toBeTrue()
            ->and(is_dir($paths->backupsDir().'/2026_03_01_000000'))->toBeTrue()
            ->and(is_dir($paths->backupsDir().'/2026_02_01_000000'))->toBeFalse();
    } finally {
        removeHousekeepingPaths($paths);
    }
});

it('removes stale downloads and extractions and leaves fresh ones', function (): void {
    $paths = housekeepingPaths();
    mkdir($paths->tmpDir(), 0777, true);

    file_put_contents($paths->tmpDir().'/core-old.zip', 'bytes');
    mkdir($paths->tmpDir().'/extract-old');
    file_put_contents($paths->tmpDir().'/extract-old/file.php', '<?php');
    file_put_contents($paths->tmpDir().'/core-fresh.zip', 'bytes');

    $twoDaysAgo = time() - 2 * 86400;
    touch($paths->tmpDir().'/core-old.zip', $twoDaysAgo);
    touch($paths->tmpDir().'/extract-old', $twoDaysAgo);

    try {
        $removed = (new UpdateHousekeeping(new Filesystem, $paths))->pruneTemp();

        sort($removed);

        expect($removed)->toBe(['core-old.zip', 'extract-old'])
            ->and(is_file($paths->tmpDir().'/core-fresh.zip'))->toBeTrue()
            ->and(is_dir($paths->tmpDir().'/extract-old'))->toBeFalse();
    } finally {
        removeHousekeepingPaths($paths);
    }
});

/*
 * A rolled-back run sets the release's copies aside as `.name.failed-<run>`
 * siblings for a look afterwards. A failed vendor/ alone is a hundred
 * megabytes, so the next run takes them away — for finished runs only.
 */
it('removes the release copies a finished run set aside, and leaves a live run alone', function (): void {
    $paths = housekeepingPaths();

    foreach (['20260101_000000_a' => 'rolled_back', '20260102_000000_b' => 'swapped'] as $runId => $state) {
        mkdir($paths->runsDir().'/'.$runId, 0777, true);
        file_put_contents($paths->runsDir().'/'.$runId.'/journal.json', (string) json_encode([
            'state' => $state,
            'paths' => ['src/Magna' => ['state' => 'unswapped'], 'vendor' => ['state' => 'unswapped']],
        ]));

        foreach (['src/.Magna.failed-'.$runId, '.vendor.failed-'.$runId] as $failed) {
            mkdir($paths->base($failed), 0777, true);
            file_put_contents($paths->base($failed.'/copy.php'), '<?php // release copy');
        }
    }

    mkdir($paths->base('src/Magna'), 0777, true);
    file_put_contents($paths->base('src/Magna/Live.php'), '<?php // live');

    try {
        $removed = (new UpdateHousekeeping(new Filesystem, $paths))->pruneFailedCopies();

        expect($removed)->toHaveCount(2)
            ->and(is_dir($paths->base('src/.Magna.failed-20260101_000000_a')))->toBeFalse()
            ->and(is_dir($paths->base('.vendor.failed-20260101_000000_a')))->toBeFalse()
            ->and(is_dir($paths->base('src/.Magna.failed-20260102_000000_b')))->toBeTrue()
            ->and(is_dir($paths->base('.vendor.failed-20260102_000000_b')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Live.php')))->toBeTrue();
    } finally {
        removeHousekeepingPaths($paths);
    }
});

it('keeps the newest finished runs and never touches one still in progress', function (): void {
    $paths = housekeepingPaths();

    $runs = [
        '20260101_000000_a' => 'completed',
        '20260102_000000_b' => 'failed',
        '20260103_000000_c' => 'finalize_pending',
        '20260104_000000_d' => 'completed',
        '20260105_000000_e' => 'completed',
        '20260106_000000_f' => 'rolled_back',
        '20260107_000000_g' => 'completed',
    ];

    foreach ($runs as $name => $state) {
        mkdir($paths->runsDir().'/'.$name, 0777, true);
        file_put_contents($paths->runsDir().'/'.$name.'/journal.json', (string) json_encode(['state' => $state]));
    }

    try {
        $removed = (new UpdateHousekeeping(new Filesystem, $paths))->pruneRuns(keep: 3);

        expect($removed)->toBe(['20260104_000000_d', '20260102_000000_b', '20260101_000000_a'])
            ->and(is_dir($paths->runsDir().'/20260103_000000_c'))->toBeTrue()
            ->and(is_dir($paths->runsDir().'/20260107_000000_g'))->toBeTrue();
    } finally {
        removeHousekeepingPaths($paths);
    }
});

it('does nothing when there is nothing to keep house in', function (): void {
    $paths = housekeepingPaths();

    try {
        $housekeeping = new UpdateHousekeeping(new Filesystem, $paths);

        expect($housekeeping->pruneBackups())->toBe([])
            ->and($housekeeping->pruneTemp())->toBe([]);
    } finally {
        removeHousekeepingPaths($paths);
    }
});
