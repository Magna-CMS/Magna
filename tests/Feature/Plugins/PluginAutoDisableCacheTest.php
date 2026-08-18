<?php

declare(strict_types=1);

/**
 * Auto-disable must clear the cached Filament panel components.
 *
 * A plugin whose files disappear is auto-disabled at boot — but a cached
 * panel manifest built while it was healthy still advertises its pages and
 * widgets. Their routes never register again, so every cached nav item or
 * dashboard widget that calls getUrl() throws RouteNotFoundException and
 * takes the whole admin panel down. Manual disable() clears the cache; the
 * automatic path has to as well.
 */

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function brokenPluginRecord(): PluginRecord
{
    return PluginRecord::query()->create([
        'name' => 'ghost/plugin',
        'display_name' => 'Ghost Plugin',
        'version' => '1.0.0',
        'enabled' => true,
        'base_path' => storage_path('app/definitely-not-a-plugin'),
        'enabled_at' => now(),
        'manifest' => [
            'name' => 'ghost/plugin',
            'displayName' => 'Ghost Plugin',
            'description' => 'Files deleted without uninstalling.',
            'version' => '1.0.0',
            'author' => 'Test',
            'license' => 'MIT',
            'compat' => ['magna' => '*', 'php' => '^8.3'],
            'entry' => 'Ghost\\Plugin\\DoesNotExist',
            'permissions' => [],
        ],
    ]);
}

/** @return list<string> The cached panel manifest files this test planted. */
function plantCachedPanelManifests(): array
{
    $dir = app()->bootstrapPath('cache/filament/panels');
    File::ensureDirectoryExists($dir);

    $planted = [];
    foreach (Filament::getPanels() as $panel) {
        $path = $dir.DIRECTORY_SEPARATOR.$panel->getId().'.php';
        File::put($path, '<?php return [];');
        $planted[] = $path;
    }

    return $planted;
}

it('clears the cached panel components when a plugin is auto-disabled at register()', function (): void {
    $record = brokenPluginRecord();
    $planted = plantCachedPanelManifests();
    expect($planted)->not->toBe([]);

    // Entry class does not exist — the register() pass auto-disables it.
    app(PluginManager::class)->bootEnabledPlugins();

    expect($record->fresh()->enabled)->toBeFalse();

    // The stale panel manifests are gone, so the next request rebuilds the
    // panel without the dead plugin's pages and widgets.
    foreach ($planted as $path) {
        expect(is_file($path))->toBeFalse();
    }
});

it('leaves the cached panel components alone when every plugin boots cleanly', function (): void {
    $planted = plantCachedPanelManifests();
    expect($planted)->not->toBe([]);

    app(PluginManager::class)->bootEnabledPlugins();

    foreach ($planted as $path) {
        expect(is_file($path))->toBeTrue();
        File::delete($path);
    }
});
