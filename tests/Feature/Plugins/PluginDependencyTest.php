<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Plugins\Exceptions\DependencyException;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Verifies the core PluginManager honours the SDK dependency graph. The
// resolution maths itself is unit-tested in the SDK (DependencyResolverTest);
// this covers the core wiring that reads stored manifests from the plugins
// table. The disable-protection path runs before any plugin entry class is
// instantiated, so it can be exercised with plain records (no on-disk plugin).

function seedPlugin(string $name, array $manifestExtra = []): void
{
    PluginRecord::create([
        'name' => $name,
        'display_name' => $name,
        'version' => '1.0.0',
        'enabled' => true,
        'base_path' => '/tmp/'.$name,
        'manifest' => [
            'name' => $name,
            'displayName' => $name,
            'description' => 'x',
            'version' => '1.0.0',
            'author' => 'Acme',
            'license' => 'MIT',
            'compat' => ['magna' => '^1.0'],
            'entry' => 'Acme\\X\\Plugin',
            'permissions' => [],
            ...$manifestExtra,
        ],
    ]);
}

it('refuses to disable a plugin another enabled plugin requires', function (): void {
    seedPlugin('acme/core');
    seedPlugin('acme/app', ['requires' => ['acme/core' => '^1.0']]);

    /** @var PluginManager $manager */
    $manager = app(PluginManager::class);

    expect(fn () => $manager->disable('acme/core'))
        ->toThrow(DependencyException::class, 'Cannot disable "acme/core": "acme/app" requires it.');
});
