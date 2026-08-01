<?php

declare(strict_types=1);

use Magna\Plugins\Exceptions\InvalidManifestException;
use Magna\Plugins\PluginPackageName;

/**
 * The manifest `name` is attacker-controlled on the Core Plugin Manager's zip
 * upload and is used to build filesystem paths (plugins-dev/{vendor}/{package},
 * and the bundled-source lookup in PluginSource). Anything that escapes the
 * application root must be rejected before it reaches a path join.
 */
it('accepts real package names', function (string $name): void {
    expect(PluginPackageName::isValid($name))->toBeTrue();
})->with([
    'magna-cms/marketplace',
    'magna/plugin-manager',
    'acme/dating',
    'a1/b2',
    'vendor/pkg-with--dashes',
    'vendor/pkg.dot',
    'vendor/pkg_underscore',
]);

it('rejects a name that would escape the application root', function (string $name): void {
    expect(PluginPackageName::isValid($name))->toBeFalse();
})->with([
    'traversal' => '../../etc/passwd',
    'traversal mid-name' => 'magna/../../../etc',
    'absolute posix' => '/etc/passwd',
    'absolute windows' => 'C:/Windows/System32',
    'no vendor' => 'marketplace',
    'trailing slash' => 'magna/',
    'extra segment' => 'magna/plugin/manager',
    'null byte' => "magna/plugin\0",
    'uppercase' => 'Magna/Plugin',
    'space' => 'magna/plugin manager',
    'empty' => '',
]);

it('throws on an invalid name', function (): void {
    PluginPackageName::assertValid('../../evil');
})->throws(InvalidManifestException::class, 'is not a valid plugin package name');
