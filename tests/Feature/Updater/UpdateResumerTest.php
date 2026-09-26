<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Magna\Updater\Run\RunOutcome;
use Magna\Updater\Run\RunState;
use Magna\Updater\Run\UpdateJournal;
use Magna\Updater\Run\UpdateResumer;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Whoever finds an unfinished run gets the same answer: finish it, leave it
 * alone, or close it out.
 */
function resumerInstall(): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-resumer-'.bin2hex(random_bytes(6));
    mkdir($base.'/src/Magna', 0777, true);
    file_put_contents($base.'/src/Magna/Installed.php', '<?php');
    mkdir($base.'/storage/app', 0777, true);

    $paths = new UpdatePaths($base, $base.'/storage');
    app()->instance(UpdatePaths::class, $paths);

    return $paths;
}

function ageJournal(UpdatePaths $paths, string $runId): void
{
    $file = $paths->runsDir().'/'.$runId.'/'.UpdateJournal::FILENAME;
    $data = json_decode((string) file_get_contents($file), true);
    $data['heartbeat_at'] = '2020-01-01T00:00:00+00:00';
    file_put_contents($file, (string) json_encode($data));
}

it('has nothing to do when no run is pending', function (): void {
    $paths = resumerInstall();

    try {
        $resumer = app(UpdateResumer::class);

        expect($resumer->resume()->kind)->toBe(RunOutcome::NOTHING)
            ->and($resumer->rollback()->kind)->toBe(RunOutcome::NOTHING);
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('leaves a run that is still being worked on alone', function (): void {
    $paths = resumerInstall();

    try {
        UpdateJournal::create($paths, 'run1', ['to' => '99.0.0'])->transition(RunState::Downloaded);

        expect(app(UpdateResumer::class)->resume()->kind)->toBe(RunOutcome::BUSY)
            ->and(UpdateJournal::latestPending($paths)?->state())->toBe(RunState::Downloaded);
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

/*
 * The one window the two-rename swap leaves: a process killed between the
 * first path's renames and the engine's last word. The tree is neither
 * release, and the only right move is back — automatically, since nothing
 * else can run new code that is only half on disk.
 */
it('rolls back a run whose process died in the middle of the switch', function (): void {
    $paths = resumerInstall();

    try {
        // src/Magna was swapped (previous content displaced), app was not yet.
        $displaced = $paths->base('src/.Magna.replaced-run2');
        rename($paths->base('src/Magna'), $displaced);
        mkdir($paths->base('src/Magna'), 0777, true);
        file_put_contents($paths->base('src/Magna/Delivered.php'), '<?php');

        $journal = UpdateJournal::create($paths, 'run2', ['to' => '99.0.0', 'from' => '1.0.0']);
        $journal->recordPath('src/Magna', ['staged' => null, 'displaced' => $displaced, 'state' => 'swapped']);
        $journal->transition(RunState::Swapped);
        Artisan::call('down');
        ageJournal($paths, 'run2');

        $outcome = app(UpdateResumer::class)->resume();

        expect($outcome->kind)->toBe(RunOutcome::ROLLED_BACK)
            ->and($outcome->message)->toContain('stopped partway')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Delivered.php')))->toBeFalse()
            ->and(is_dir($displaced))->toBeFalse()
            ->and(app()->maintenanceMode()->active())->toBeFalse()
            ->and(UpdateJournal::open($paths, 'run2')?->state())->toBe(RunState::RolledBack);
    } finally {
        Artisan::call('up');
        (new Filesystem)->remove($paths->basePath);
    }
});

it('leaves a switch that is still in progress to the process performing it', function (): void {
    $paths = resumerInstall();

    try {
        $journal = UpdateJournal::create($paths, 'run3', ['to' => '99.0.0']);
        $journal->transition(RunState::Swapped);

        expect(app(UpdateResumer::class)->resume()->kind)->toBe(RunOutcome::BUSY)
            ->and(UpdateJournal::open($paths, 'run3')?->state())->toBe(RunState::Swapped);
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('closes out a run whose process died before switching, discarding what it staged', function (): void {
    $paths = resumerInstall();

    try {
        $staging = $paths->base('src/.Magna.incoming-run1');
        mkdir($staging, 0777, true);
        file_put_contents($staging.'/Delivered.php', '<?php');

        $journal = UpdateJournal::create($paths, 'run1', ['to' => '99.0.0']);
        $journal->recordPath('src/Magna', ['staged' => $staging, 'state' => 'staged']);
        $journal->transition(RunState::Staged);
        ageJournal($paths, 'run1');

        $outcome = app(UpdateResumer::class)->resume();

        expect($outcome->kind)->toBe(RunOutcome::FAILED)
            ->and($outcome->message)->toContain('stopped before switching')
            ->and(is_dir($staging))->toBeFalse()
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(UpdateJournal::latestPending($paths))->toBeNull()
            ->and(UpdateJournal::open($paths, 'run1')?->state())->toBe(RunState::Failed);
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('lifts maintenance mode when closing out a run that had already gone down', function (): void {
    $paths = resumerInstall();

    try {
        UpdateJournal::create($paths, 'run1', ['to' => '99.0.0'])->transition(RunState::Down);
        ageJournal($paths, 'run1');
        Artisan::call('down');

        app(UpdateResumer::class)->resume();

        expect(app()->maintenanceMode()->active())->toBeFalse();
    } finally {
        Artisan::call('up');
        (new Filesystem)->remove($paths->basePath);
    }
});

it('will not roll back a run that finished', function (): void {
    $paths = resumerInstall();

    try {
        UpdateJournal::create($paths, 'run1', ['to' => '99.0.0'])->transition(RunState::Completed);

        $outcome = app(UpdateResumer::class)->rollback('run1');

        expect($outcome->kind)->toBe(RunOutcome::FAILED)
            ->and($outcome->message)->toContain('finished');
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});
