<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Magna\Plugins\PluginMigrator;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Artisan::call reports most migration failures as an exit code, not an
 * exception. Unchecked, enable() and the zip updater finished with new code
 * over an old schema — and nothing to roll back on.
 */
beforeEach(function (): void {
    $this->pluginDir = sys_get_temp_dir().'/magna-migrator-'.bin2hex(random_bytes(6));
    mkdir($this->pluginDir.'/database/migrations', 0755, true);
});

afterEach(function (): void {
    (new Filesystem)->remove($this->pluginDir);
});

it('throws when migrate exits non-zero', function (): void {
    Artisan::shouldReceive('call')->once()->andReturn(1);
    Artisan::shouldReceive('output')->once()->andReturn('SQLSTATE[42P01]: relation does not exist');

    expect(fn () => (new PluginMigrator)->run($this->pluginDir))
        ->toThrow(RuntimeException::class, 'Plugin migrations failed (exit 1)');
});

it('returns the command output on success', function (): void {
    Artisan::shouldReceive('call')->once()->andReturn(0);
    Artisan::shouldReceive('output')->once()->andReturn('Nothing to migrate.');

    expect((new PluginMigrator)->run($this->pluginDir))->toBe('Nothing to migrate.');
});

it('returns null when the plugin ships no migrations', function (): void {
    (new Filesystem)->remove($this->pluginDir.'/database/migrations');

    Artisan::shouldReceive('call')->never();

    expect((new PluginMigrator)->run($this->pluginDir))->toBeNull();
});
