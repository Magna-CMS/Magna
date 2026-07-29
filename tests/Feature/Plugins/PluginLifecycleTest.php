<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
use Magna\Plugins\Exceptions\InvalidManifestException;
use Magna\Plugins\Exceptions\PluginNotFoundException;
use Magna\Plugins\Manifest;
use Magna\Plugins\ManifestValidator;
use Magna\Plugins\PluginManager;

// ── Manifest validation ─────────────────────────────────────────────────────

it('accepts a valid magna.json manifest', function (): void {
    $data = validManifestData();
    expect(fn () => ManifestValidator::validate($data))->not->toThrow(InvalidManifestException::class);
});

it('rejects a manifest missing required fields', function (string $field): void {
    $data = validManifestData();
    unset($data[$field]);
    expect(fn () => ManifestValidator::validate($data))->toThrow(InvalidManifestException::class);
})->with(['name', 'displayName', 'description', 'version', 'author', 'license', 'compat', 'entry', 'permissions']);

it('rejects a manifest with invalid name format', function (): void {
    $data = validManifestData(['name' => 'NotVendorSlashPackage']);
    expect(fn () => ManifestValidator::validate($data))->toThrow(InvalidManifestException::class);
});

it('rejects a manifest missing compat.magna', function (): void {
    $data = validManifestData(['compat' => ['php' => '^8.3']]);
    expect(fn () => ManifestValidator::validate($data))->toThrow(InvalidManifestException::class);
});

it('rejects a manifest where permissions is not an array', function (): void {
    $data = validManifestData(['permissions' => 'not-an-array']);
    expect(fn () => ManifestValidator::validate($data))->toThrow(InvalidManifestException::class);
});

it('rejects a manifest with an invalid permission key format', function (): void {
    $data = validManifestData(['permissions' => ['NoDotsHere']]);
    expect(fn () => ManifestValidator::validate($data))->toThrow(InvalidManifestException::class);
});

// S1-07: reserved core module slugs must be rejected — a plugin using one
// could otherwise shadow the matching core module's routes, since core
// modules boot before PluginsServiceProvider (see MagnaServiceProvider).
it('rejects a manifest whose package slug collides with a core module', function (string $slug): void {
    $data = validManifestData(['name' => "acme/{$slug}"]);
    expect(fn () => ManifestValidator::validate($data))->toThrow(InvalidManifestException::class);
})->with([
    'admin', 'audit', 'auth', 'blocks', 'content', 'delivery', 'install',
    'management', 'media', 'plugins', 'privacy', 'settings', 'users', 'webhooks',
]);

it('allows "marketplace" as a package slug (not a routed core module)', function (): void {
    $data = validManifestData(['name' => 'magna-cms/marketplace']);
    expect(fn () => ManifestValidator::validate($data))->not->toThrow(InvalidManifestException::class);
});

it('builds a Manifest value object from a valid array', function (): void {
    $data = validManifestData();
    $manifest = Manifest::fromArray($data);

    expect($manifest->name)->toBe('test/plugin')
        ->and($manifest->magnaCompat)->toBe('^1.0')
        ->and($manifest->entryClass)->toBe('Test\\Plugin\\TestPlugin')
        ->and($manifest->permissions)->toBe(['test.resource.view']);
});

// ── Compat checking ─────────────────────────────────────────────────────────

it('accepts a plugin whose magna compat satisfies the core version', function (): void {
    $manifest = Manifest::fromArray(validManifestData(['compat' => ['magna' => '^1.0']]));
    expect($manifest->isCompatibleWith('1.3.0-beta'))->toBeTrue();
});

it('refuses to enable a plugin incompatible with the core version', function (): void {
    $manifest = Manifest::fromArray(validManifestData(['compat' => ['magna' => '^2.0']]));
    expect($manifest->isCompatibleWith('1.3.0-beta'))->toBeFalse();
});

it('throws PluginNotFoundException when enabling an unknown plugin', function (): void {
    /** @var PluginManager $manager */
    $manager = app(PluginManager::class);
    expect(fn () => $manager->enable('nonexistent/plugin'))->toThrow(PluginNotFoundException::class);
});

// NOTE: enable/disable/uninstall lifecycle coverage (PluginCompatibilityException,
// filament-cache-clear-on-enable, content-type registration/purge on uninstall)
// previously ran against magna/hello-world as a real on-disk fixture plugin.
// That plugin was removed; this coverage needs a replacement fixture plugin
// before it can come back — see docs/core-plugin-manager-plan.md.

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validManifestData(array $overrides = []): array
{
    return array_merge([
        'name' => 'test/plugin',
        'displayName' => 'Test Plugin',
        'description' => 'A test plugin.',
        'version' => '1.0.0',
        'author' => 'Test Author',
        'license' => 'MIT',
        'compat' => ['magna' => '^1.0', 'php' => '^8.3'],
        'entry' => 'Test\\Plugin\\TestPlugin',
        'provides' => [],
        'permissions' => ['test.resource.view'],
    ], $overrides);
}
