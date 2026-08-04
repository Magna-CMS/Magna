<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Plugins\Exceptions\InvalidManifestException;
use Magna\Plugins\PluginFileRemover;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Regression: uninstall removed the PluginRecord and nothing else. The files
 * stayed, and the Plugins page calls syncDiscovered() on every load — which
 * re-created the row from those files. The plugin came back immediately, and a
 * hub-bundled one could never be removed at all.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/magna-uninstall-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/plugins-dev/acme/widgets', 0755, true);
    mkdir($this->root.'/vendor/acme/widgets', 0755, true);

    file_put_contents($this->root.'/plugins-dev/acme/widgets/magna.json', '{"name":"acme/widgets"}');
    file_put_contents($this->root.'/vendor/acme/widgets/magna.json', '{"name":"acme/widgets"}');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['php' => '^8.3', 'acme/widgets' => '@dev'],
        'repositories' => [
            ['type' => 'path', 'url' => 'plugins-dev/acme/widgets'],
            ['type' => 'path', 'url' => 'plugins-dev/other/plugin'],
        ],
    ], JSON_PRETTY_PRINT));

    $this->remover = new PluginFileRemover($this->root);
});

afterEach(function (): void {
    (new Filesystem)->remove($this->root);
});

/** @return array<string, mixed> */
function composerAt(string $root): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

// A hub ships the source in plugins-dev/ and lets Composer mirror it into
// vendor/. Removing one copy leaves the other for discovery to find.
it('removes both copies of a bundled plugin', function (): void {
    $removed = $this->remover->remove('acme/widgets');

    expect(is_dir($this->root.'/plugins-dev/acme/widgets'))->toBeFalse()
        ->and(is_dir($this->root.'/vendor/acme/widgets'))->toBeFalse()
        ->and($removed)->toHaveCount(2);
});

it('drops the composer wiring so later Composer commands still resolve', function (): void {
    $this->remover->remove('acme/widgets');

    $composer = composerAt($this->root);

    expect($composer['require'])->not->toHaveKey('acme/widgets')
        ->and($composer['require'])->toHaveKey('php')
        // Another plugin's repository must survive untouched.
        ->and($composer['repositories'])->toBe([
            ['type' => 'path', 'url' => 'plugins-dev/other/plugin'],
        ]);
});

it('leaves composer.json alone when the plugin was never wired in', function (): void {
    $before = (string) file_get_contents($this->root.'/composer.json');

    mkdir($this->root.'/vendor/acme/gadgets', 0755, true);
    $this->remover->remove('acme/gadgets');

    expect((string) file_get_contents($this->root.'/composer.json'))->toBe($before);
});

it('is a no-op for a plugin with nothing on disk', function (): void {
    expect($this->remover->remove('acme/nothing'))->toBe([]);
});

// Deleting files is opt-in, not what uninstall does by default. It is a
// recursive delete against a path derived from a package name, and a caller
// that only wants the record gone should not have to know that — an
// unconditional version wiped a real plugin during a test that uninstalls by
// its real name against the real project root.
it('leaves files alone unless removal was asked for', function (): void {
    PluginRecord::create([
        'name' => 'acme/widgets',
        'display_name' => 'Acme Widgets',
        'version' => '1.0.0',
        'enabled' => false,
        'base_path' => $this->root.'/plugins-dev/acme/widgets',
        'manifest' => ['name' => 'acme/widgets'],
    ]);

    app(PluginManager::class)->uninstall('acme/widgets');

    expect(PluginRecord::query()->where('name', 'acme/widgets')->exists())->toBeFalse()
        ->and(is_dir($this->root.'/plugins-dev/acme/widgets'))->toBeTrue();
});

// The recorded base_path is a hint, never an instruction: this class issues
// recursive deletes, and a name that escapes the two known trees must never
// resolve to a path outside them.
it('refuses a package name that would escape the application root', function (): void {
    expect(fn () => $this->remover->remove('../../etc'))
        ->toThrow(InvalidManifestException::class);
});

it('never deletes anything outside plugins-dev and vendor', function (): void {
    $outside = $this->root.'/secrets';
    mkdir($outside, 0755, true);
    file_put_contents($outside.'/keep.txt', 'untouched');

    $this->remover->remove('acme/widgets');

    expect(is_file($outside.'/keep.txt'))->toBeTrue();
});

// Composer resolves a `type: path` repository by symlinking vendor/{package}
// at the source on hosts that allow it. Following that link would delete the
// source through the link and leave the link dangling.
it('skips a symlinked vendor copy rather than deleting through it', function (): void {
    (new Filesystem)->remove($this->root.'/vendor/acme/widgets');

    if (! @symlink($this->root.'/plugins-dev/acme/widgets', $this->root.'/vendor/acme/widgets')) {
        $this->markTestSkipped('This system does not allow creating symlinks.');
    }

    $removed = $this->remover->remove('acme/widgets');

    expect($removed)->toBe([$this->root.'/plugins-dev/acme/widgets'])
        ->and(is_link($this->root.'/vendor/acme/widgets'))->toBeTrue();
});

// The guard that would have prevented this class of accident outright: an
// uninstall deleted plugins-dev/magna/docs — a developer's working checkout —
// and it was recoverable only because .git happened to survive. A release
// archive and a Composer install both ship without one.
it('refuses to delete a directory that is a git working copy', function (): void {
    mkdir($this->root.'/plugins-dev/acme/widgets/.git', 0755, true);

    $removed = $this->remover->remove('acme/widgets');

    expect(is_dir($this->root.'/plugins-dev/acme/widgets'))->toBeTrue()
        ->and($removed)->toBe([str_replace('\\', '/', $this->root).'/vendor/acme/widgets']);
});

it('is what stops discovery resurrecting an uninstalled plugin', function (): void {
    PluginRecord::create([
        'name' => 'acme/widgets',
        'display_name' => 'Acme Widgets',
        'version' => '1.0.0',
        'enabled' => false,
        'base_path' => $this->root.'/plugins-dev/acme/widgets',
        'manifest' => ['name' => 'acme/widgets'],
    ]);

    PluginRecord::query()->where('name', 'acme/widgets')->delete();
    $this->remover->remove('acme/widgets');

    // Nothing left for PluginDiscovery to find, so syncDiscovered() has
    // nothing to re-create the row from.
    expect(glob($this->root.'/plugins-dev/acme/*'))->toBe([])
        ->and(glob($this->root.'/vendor/acme/*'))->toBe([]);
});
