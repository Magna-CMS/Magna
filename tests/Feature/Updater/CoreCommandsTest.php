<?php

declare(strict_types=1);

use Magna\MagnaServiceProvider;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The shell's view of the updater, for the host where the panel is the
 * thing that is not answering.
 */
function commandsInstall(): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-commands-'.bin2hex(random_bytes(6));
    mkdir($base.'/storage/app', 0777, true);

    $paths = new UpdatePaths($base, $base.'/storage');
    app()->instance(UpdatePaths::class, $paths);

    return $paths;
}

it('reports the running version and the absence of a recorded delivery', function (): void {
    $paths = commandsInstall();

    try {
        $this->artisan('magna:core:status')
            ->expectsOutputToContain('Running:   v'.MagnaServiceProvider::VERSION)
            ->expectsOutputToContain('Recorded:  nothing')
            ->expectsOutputToContain('Pending:   none')
            ->assertSuccessful();
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('has nothing to resume or roll back on a quiet install', function (): void {
    $paths = commandsInstall();

    try {
        $this->artisan('magna:core:resume')->expectsOutputToContain('No update is waiting.')->assertSuccessful();
        $this->artisan('magna:core:rollback')->expectsOutputToContain('No update is waiting.')->assertSuccessful();
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('refuses a repair without an archive and its published checksum', function (): void {
    $paths = commandsInstall();

    try {
        $this->artisan('magna:core:repair')->assertExitCode(2);
        $this->artisan('magna:core:repair', ['--archive' => $paths->base('missing.zip'), '--sha256' => str_repeat('a', 64)])->assertExitCode(2);
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});
