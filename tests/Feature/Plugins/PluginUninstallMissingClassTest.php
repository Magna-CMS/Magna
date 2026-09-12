<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Regression: an enabled plugin whose entry class no longer autoloads (files
 * deleted from disk, or a broken zip extraction that never wrote them) could
 * not be uninstalled. uninstall() calls disable(), disable() instantiated the
 * entry class to run its disable() hook, and the RuntimeException it threw —
 * "The plugin files may have been deleted without uninstalling via the admin
 * panel." — aborted the very uninstall it was pointing people at. The hook is
 * best-effort; the record cleanup must proceed without it.
 */

/** @return array<string, mixed> */
function ghostManifest(): array
{
    return [
        'name' => 'acme/ghost',
        'displayName' => 'Ghost',
        'description' => 'Files deleted after installation.',
        'version' => '1.0.0',
        'author' => 'Acme',
        'license' => 'MIT',
        'compat' => ['magna' => '^1.0', 'php' => '^8.3'],
        'entry' => 'Acme\\Ghost\\GhostPlugin',
        'provides' => [],
        'permissions' => [],
    ];
}

function createGhostRecord(): PluginRecord
{
    return PluginRecord::create([
        'name' => 'acme/ghost',
        'display_name' => 'Ghost',
        'version' => '1.0.0',
        'enabled' => true,
        'base_path' => base_path('plugins-dev/acme/ghost'),
        'manifest' => ghostManifest(),
    ]);
}

it('uninstalls an enabled plugin whose entry class no longer exists', function (): void {
    createGhostRecord();

    app(PluginManager::class)->uninstall('acme/ghost');

    expect(PluginRecord::query()->where('name', 'acme/ghost')->exists())->toBeFalse();
});

it('disables an enabled plugin whose entry class no longer exists', function (): void {
    createGhostRecord();

    app(PluginManager::class)->disable('acme/ghost');

    /** @var PluginRecord $record */
    $record = PluginRecord::query()->where('name', 'acme/ghost')->firstOrFail();

    expect($record->enabled)->toBeFalse()
        ->and($record->disabled_at)->not->toBeNull();
});
