<?php

declare(strict_types=1);

use Magna\Plugins\Exceptions\PluginCompatibilityException;
use Magna\Plugins\Manifest;
use Magna\Plugins\PluginCompatibilityCheck;
use Tests\TestCase;

uses(TestCase::class);

/**
 * `compat.php` was parsed since the manifest existed and enforced nowhere:
 * a plugin that declared `^8.4` enabled on an 8.3 host and found out at
 * runtime. The manifest's word is now checked where the core version's is.
 */
function compatManifest(array $compat): Manifest
{
    return Manifest::fromArray([
        'name' => 'acme/compat-probe',
        'displayName' => 'Compat Probe',
        'description' => 'Probe.',
        'version' => '1.0.0',
        'author' => 'Acme',
        'license' => 'MIT',
        'compat' => $compat,
        'entry' => 'Acme\\CompatProbe\\Plugin',
        'permissions' => [],
    ]);
}

it('accepts a plugin whose php range covers the running php', function (): void {
    $manifest = compatManifest(['magna' => '^1.0', 'php' => '>=8.3']);

    expect(PluginCompatibilityCheck::phpIncompatible($manifest, '8.3.12'))->toBeFalse()
        ->and(PluginCompatibilityCheck::phpIncompatible($manifest, '8.4.0'))->toBeFalse();
});

it('refuses a plugin whose php range rules the running php out', function (): void {
    $manifest = compatManifest(['magna' => '^1.0', 'php' => '^8.4']);

    expect(PluginCompatibilityCheck::phpIncompatible($manifest, '8.3.12'))->toBeTrue();

    if (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3) {
        expect(fn () => PluginCompatibilityCheck::assertCompatible($manifest, '1.4.4'))
            ->toThrow(PluginCompatibilityException::class, 'requires PHP ^8.4');
    }
});

it('still refuses on the core range first', function (): void {
    $manifest = compatManifest(['magna' => '^0.9', 'php' => '>=8.3']);

    expect(fn () => PluginCompatibilityCheck::assertCompatible($manifest, '1.4.4'))
        ->toThrow(PluginCompatibilityException::class, 'requires magna ^0.9');
});

it('does not hold a php constraint it cannot parse against the plugin', function (): void {
    $manifest = compatManifest(['magna' => '^1.0', 'php' => 'not a constraint at all']);

    expect(PluginCompatibilityCheck::phpIncompatible($manifest, '8.3.12'))->toBeFalse();
});
