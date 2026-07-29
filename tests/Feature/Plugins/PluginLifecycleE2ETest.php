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
    skipWithoutDevPlugin('magna/docs');

    return app(PluginManager::class);
}

it('enables, then disables preserving the record (data not destroyed)', function (): void {
    $manager = lifecycleManager();
    $manager->enable('magna/docs');

    expect(PluginRecord::query()->where('name', 'magna/docs')->where('enabled', true)->exists())->toBeTrue();

    $manager->disable('magna/docs');

    // Disable preserves the record + data — only the enabled flag flips.
    $record = PluginRecord::query()->where('name', 'magna/docs')->first();
    expect($record)->not->toBeNull()
        ->and($record->enabled)->toBeFalse();
});

it('re-enabling after disable is safe and idempotent', function (): void {
    $manager = lifecycleManager();

    $manager->enable('magna/docs');
    $manager->disable('magna/docs');
    $manager->enable('magna/docs');

    expect(PluginRecord::query()->where('name', 'magna/docs')->where('enabled', true)->count())->toBe(1)
        ->and($manager->getEnabled())->toHaveKey('magna/docs');
});

it('uninstall removes the plugin record', function (): void {
    $manager = lifecycleManager();
    $manager->enable('magna/docs');

    $manager->uninstall('magna/docs');

    expect(PluginRecord::query()->where('name', 'magna/docs')->exists())->toBeFalse();
});

it('syncDiscovered is idempotent — never duplicates a plugin row', function (): void {
    $manager = lifecycleManager();

    $manager->syncDiscovered();
    $manager->syncDiscovered();

    expect(PluginRecord::query()->where('name', 'magna/docs')->count())->toBe(1);
});
