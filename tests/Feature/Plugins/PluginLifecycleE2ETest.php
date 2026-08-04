<?php

declare(strict_types=1);

use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

// End-to-end plugin lifecycle validation against the real plugin manager and a
// real discovered plugin (magna/docs). Complements PluginLifecycleTest (which
// covers manifest/compat validation) by exercising enable → disable → re-enable
// → uninstall as an enterprise deployment would.

function lifecycleManager(): PluginManager
{
    skipWithoutDevPlugin('magna-cms/docs');

    return app(PluginManager::class);
}

it('enables, then disables preserving the record (data not destroyed)', function (): void {
    $manager = lifecycleManager();
    $manager->enable('magna-cms/docs');

    expect(PluginRecord::query()->where('name', 'magna-cms/docs')->where('enabled', true)->exists())->toBeTrue();

    $manager->disable('magna-cms/docs');

    // Disable preserves the record + data — only the enabled flag flips.
    $record = PluginRecord::query()->where('name', 'magna-cms/docs')->first();
    expect($record)->not->toBeNull()
        ->and($record->enabled)->toBeFalse();
});

it('re-enabling after disable is safe and idempotent', function (): void {
    $manager = lifecycleManager();

    $manager->enable('magna-cms/docs');
    $manager->disable('magna-cms/docs');
    $manager->enable('magna-cms/docs');

    expect(PluginRecord::query()->where('name', 'magna-cms/docs')->where('enabled', true)->count())->toBe(1)
        ->and($manager->getEnabled())->toHaveKey('magna-cms/docs');
});

it('uninstall removes the plugin record', function (): void {
    $manager = lifecycleManager();
    $manager->enable('magna-cms/docs');

    $manager->uninstall('magna-cms/docs');

    expect(PluginRecord::query()->where('name', 'magna-cms/docs')->exists())->toBeFalse();
});

it('syncDiscovered is idempotent — never duplicates a plugin row', function (): void {
    $manager = lifecycleManager();

    $manager->syncDiscovered();
    $manager->syncDiscovered();

    expect(PluginRecord::query()->where('name', 'magna-cms/docs')->count())->toBe(1);
});
