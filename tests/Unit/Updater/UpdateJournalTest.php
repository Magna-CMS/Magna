<?php

declare(strict_types=1);

use Magna\Updater\Run\RunState;
use Magna\Updater\Run\UpdateJournal;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The file that outlives the process: where a run is, what it did to which
 * path, and whether anyone is still working on it.
 */
function journalPaths(): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-journal-'.bin2hex(random_bytes(6));
    mkdir($base.'/storage', 0777, true);

    return new UpdatePaths($base, $base.'/storage');
}

it('is created, reopened, and moved through states with a heartbeat', function (): void {
    $paths = journalPaths();

    try {
        $journal = UpdateJournal::create($paths, 'run1', ['mode' => 'update', 'from' => '1.0.0', 'to' => '2.0.0']);

        expect($journal->state())->toBe(RunState::Created)
            ->and($journal->string('to'))->toBe('2.0.0')
            ->and(is_file($paths->runsDir().'/run1/'.UpdateJournal::FILENAME))->toBeTrue();

        $journal->transition(RunState::Staged);
        $journal->recordPath('src/Magna', ['staged' => '/x', 'state' => 'staged']);
        $journal->recordPath('src/Magna', ['state' => 'swapped']);

        $reopened = UpdateJournal::open($paths, 'run1');

        expect($reopened?->state())->toBe(RunState::Staged)
            ->and($reopened?->paths()['src/Magna'])->toBe(['staged' => '/x', 'state' => 'swapped'])
            ->and($reopened?->isAbandoned())->toBeFalse();
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('finds the newest run that is not over', function (): void {
    $paths = journalPaths();

    try {
        UpdateJournal::create($paths, '20260101_000000_aaaa', ['to' => '1.0.1'])->transition(RunState::Completed);
        UpdateJournal::create($paths, '20260102_000000_bbbb', ['to' => '1.0.2'])->transition(RunState::FinalizePending);
        UpdateJournal::create($paths, '20260103_000000_cccc', ['to' => '1.0.3'])->transition(RunState::Failed);

        expect(UpdateJournal::latestPending($paths)?->runId())->toBe('20260102_000000_bbbb');

        UpdateJournal::open($paths, '20260102_000000_bbbb')?->transition(RunState::RolledBack);

        expect(UpdateJournal::latestPending($paths))->toBeNull();
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('calls a run abandoned when its heartbeat is old and it is not over', function (): void {
    $paths = journalPaths();

    try {
        UpdateJournal::create($paths, 'run1', ['to' => '2.0.0'])->transition(RunState::Staged);

        // Age the heartbeat behind the journal's back; set() would refresh it.
        $file = $paths->runsDir().'/run1/'.UpdateJournal::FILENAME;
        $data = json_decode((string) file_get_contents($file), true);
        $data['heartbeat_at'] = '2020-01-01T00:00:00+00:00';
        file_put_contents($file, (string) json_encode($data));

        $stale = UpdateJournal::open($paths, 'run1');

        expect($stale?->isAbandoned())->toBeTrue();

        $stale?->transition(RunState::Failed);
        $data = json_decode((string) file_get_contents($file), true);
        $data['heartbeat_at'] = '2020-01-01T00:00:00+00:00';
        file_put_contents($file, (string) json_encode($data));

        expect(UpdateJournal::open($paths, 'run1')?->isAbandoned())->toBeFalse();
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('locks a run against a second holder until released', function (): void {
    $paths = journalPaths();

    try {
        $first = UpdateJournal::create($paths, 'run1', ['to' => '2.0.0']);
        $second = UpdateJournal::open($paths, 'run1');

        expect($first->acquire())->toBeTrue()
            ->and($first->acquire())->toBeTrue()
            ->and($second?->acquire())->toBeFalse();

        $first->release();

        expect($second?->acquire())->toBeTrue();
        $second?->release();
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});
