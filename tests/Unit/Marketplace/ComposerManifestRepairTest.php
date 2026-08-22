<?php

declare(strict_types=1);

use Magna\Marketplace\ComposerManifestRepair;

/**
 * The failure this exists for: a release built on a developer machine shipped
 * that machine's path repository, and on the customer's server every Composer
 * command died with "The `url` supplied for the path (C:/Users/...) repository
 * does not exist" — so no plugin could be installed or updated at all.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/magna-manifest-'.bin2hex(random_bytes(6));
    mkdir($this->root);

    $this->write = function (array $manifest): void {
        file_put_contents($this->root.'/composer.json', (string) json_encode($manifest, JSON_PRETTY_PRINT));
    };

    $this->read = fn (): array => (array) json_decode((string) file_get_contents($this->root.'/composer.json'), true);
});

afterEach(function (): void {
    $remove = function (string $path) use (&$remove): void {
        foreach ((array) glob($path.'/*') as $child) {
            is_string($child) && (is_dir($child) ? $remove($child) : unlink($child));
        }

        rmdir($path);
    };

    $remove($this->root);
});

it('drops a path repository whose directory is not on this server', function (): void {
    ($this->write)([
        'repositories' => [['type' => 'path', 'url' => 'C:/Users/dev/Herd/magna-plugin-sdk', 'options' => ['symlink' => false]]],
        'require' => ['magna-cms/plugin-sdk' => '@dev', 'laravel/framework' => '^13.0'],
    ]);

    $notes = (new ComposerManifestRepair($this->root))->repair();

    $manifest = ($this->read)();

    expect($manifest)->not->toHaveKey('repositories')
        ->and($manifest['require']['magna-cms/plugin-sdk'])->toBe('*')
        ->and($manifest['require']['laravel/framework'])->toBe('^13.0')
        ->and($notes)->toHaveCount(2);
});

it('keeps the site on the version it already has', function (): void {
    mkdir($this->root.'/vendor/composer', 0755, true);
    file_put_contents($this->root.'/vendor/composer/installed.json', (string) json_encode([
        'packages' => [['name' => 'magna-cms/plugin-sdk', 'version' => 'v1.3.4']],
    ]));

    ($this->write)([
        'repositories' => [['type' => 'path', 'url' => '../magna-plugin-sdk']],
        'require' => ['magna-cms/plugin-sdk' => 'dev-main'],
    ]);

    (new ComposerManifestRepair($this->root))->repair();

    expect(($this->read)()['require']['magna-cms/plugin-sdk'])->toBe('^1.3');
});

// The proprietary plugins (Marketplace, Core Plugin Manager) are wired as path
// repositories on purpose. Repairing a broken sibling must never take them out.
it('leaves a path repository that exists alone', function (): void {
    mkdir($this->root.'/plugins-dev/magna/marketplace', 0755, true);
    file_put_contents(
        $this->root.'/plugins-dev/magna/marketplace/composer.json',
        (string) json_encode(['name' => 'magna/marketplace']),
    );

    ($this->write)([
        'repositories' => [
            ['type' => 'path', 'url' => 'plugins-dev'],
            ['type' => 'path', 'url' => 'plugins-dev/*/*'],
            ['type' => 'path', 'url' => '/opt/gone'],
        ],
        'require' => ['magna/marketplace' => '@dev'],
    ]);

    (new ComposerManifestRepair($this->root))->repair();

    $manifest = ($this->read)();

    expect($manifest['repositories'])->toBe([
        ['type' => 'path', 'url' => 'plugins-dev'],
        ['type' => 'path', 'url' => 'plugins-dev/*/*'],
    ])
        // Its own path repository is still there, so its constraint still resolves.
        ->and($manifest['require']['magna/marketplace'])->toBe('@dev');
});

it('touches nothing on a healthy manifest', function (): void {
    ($this->write)([
        'require' => ['laravel/framework' => '^13.0'],
        'minimum-stability' => 'dev',
    ]);

    $before = (string) file_get_contents($this->root.'/composer.json');

    expect((new ComposerManifestRepair($this->root))->repair())->toBe([])
        ->and((string) file_get_contents($this->root.'/composer.json'))->toBe($before)
        ->and(is_file($this->root.'/composer.json.magna-backup'))->toBeFalse();
});

it('keeps the original beside the repaired one', function (): void {
    ($this->write)([
        'repositories' => [['type' => 'path', 'url' => '/opt/gone']],
        'require' => ['magna-cms/plugin-sdk' => '@dev'],
    ]);

    (new ComposerManifestRepair($this->root))->repair();

    $backup = (array) json_decode((string) file_get_contents($this->root.'/composer.json.magna-backup'), true);

    expect($backup['repositories'])->toBe([['type' => 'path', 'url' => '/opt/gone']]);
});
