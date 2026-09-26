<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * bootstrap/update/boot-guard.php, exercised in a real PHP process: while
 * a run is armed, a fatal during bootstrap renames the previous release back
 * and clears the maintenance markers, with no framework involved. The guard
 * finds its install root from its own location, so a copy of it is placed
 * inside the fixture and required from there.
 */
function bootGuardFixture(bool $armed = true): string
{
    $base = sys_get_temp_dir().'/magna-bootguard-'.bin2hex(random_bytes(6));

    mkdir($base.'/bootstrap/update', 0777, true);
    copy(base_path('bootstrap/update/boot-guard.php'), $base.'/bootstrap/update/boot-guard.php');

    mkdir($base.'/src/Magna', 0777, true);
    file_put_contents($base.'/src/Magna/Broken.php', '<?php // the release that does not boot');
    mkdir($base.'/src/.Magna.replaced-r1', 0777, true);
    file_put_contents($base.'/src/.Magna.replaced-r1/Old.php', '<?php // the previous release');

    mkdir($base.'/storage/framework', 0777, true);
    file_put_contents($base.'/storage/framework/down', '{}');
    file_put_contents($base.'/storage/framework/maintenance.php', '<?php');

    mkdir($base.'/storage/app/magna-updates/runs/r1', 0777, true);
    file_put_contents($base.'/storage/app/magna-updates/runs/r1/journal.json', (string) json_encode([
        'run_id' => 'r1',
        'state' => 'finalize_pending',
        'to' => '99.0.0',
        'paths' => [
            'src/Magna' => ['state' => 'swapped', 'displaced' => $base.'/src/.Magna.replaced-r1'],
        ],
    ]));

    if ($armed) {
        file_put_contents($base.'/storage/app/magna-updates/boot-guard.json', (string) json_encode(['run' => 'r1']));
    }

    // What bootstrap/app.php does, followed by the fatal a broken release would raise.
    file_put_contents($base.'/probe.php', "<?php\nrequire __DIR__.'/bootstrap/update/boot-guard.php';\nthis_function_does_not_exist();\n");

    return $base;
}

it('renames the previous release back when the new code fatals while armed', function (): void {
    $base = bootGuardFixture();

    try {
        $process = new Process([PHP_BINARY, $base.'/probe.php']);
        $process->run();

        // Under the CLI the guard reports on stderr; a web request gets the page.
        expect($process->getErrorOutput())->toContain('rolled back to the previous release')
            ->and(is_file($base.'/src/Magna/Old.php'))->toBeTrue()
            ->and(is_file($base.'/src/.Magna.failed-r1/Broken.php'))->toBeTrue()
            ->and(is_dir($base.'/src/.Magna.replaced-r1'))->toBeFalse()
            ->and(is_file($base.'/storage/framework/down'))->toBeFalse()
            ->and(is_file($base.'/storage/framework/maintenance.php'))->toBeFalse()
            ->and(is_file($base.'/storage/app/magna-updates/boot-guard.json'))->toBeFalse();

        $journal = json_decode((string) file_get_contents($base.'/storage/app/magna-updates/runs/r1/journal.json'), true);

        expect($journal['state'] ?? null)->toBe('rolled_back_by_boot_guard')
            ->and($journal['boot_guard']['restored'] ?? null)->toBe(['src/Magna'])
            ->and($journal['boot_guard']['error'] ?? '')->toContain('this_function_does_not_exist');
    } finally {
        (new Filesystem)->remove($base);
    }
});

it('does nothing when no run is armed', function (): void {
    $base = bootGuardFixture(armed: false);

    try {
        $process = new Process([PHP_BINARY, $base.'/probe.php']);
        $process->run();

        expect($process->getErrorOutput())->not->toContain('rolled back')
            ->and(is_file($base.'/src/Magna/Broken.php'))->toBeTrue()
            ->and(is_file($base.'/storage/framework/down'))->toBeTrue();
    } finally {
        (new Filesystem)->remove($base);
    }
});
