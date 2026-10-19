<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Plugins\PluginRecord;
use Magna\Themes\EnabledPluginVersions;
use Magna\Themes\InvalidThemeException;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeManifest;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A theme may declare the plugin versions its layout is drawn against.
 *
 * Until `compat.plugins` existed, `compat.magna` was the only key a theme
 * manifest carried, and a theme whose rendering depends on a PLUGIN had no way
 * to say so. `settings.row.columnTemplate` is a magna/pages feature, and an
 * unknown block setting is dropped on the way in — so a theme built on it
 * installs happily on an older pages and draws every two-up band as equal
 * columns. No error, no warning, nothing in a log: just the layout being wrong
 * in exactly the way the author was asked to fix. One theme had 26 such bands.
 *
 * So the floor refuses activation, the way a plugin's `requires` refuses a
 * boot. The three cases below are the ones that decide whether that refusal is
 * trustworthy: met, unmet, and unknowable.
 */
beforeEach(function (): void {
    $this->themesRoot = storage_path('framework/testing/themes-compat-'.uniqid());
    config(['magna.themes_path' => $this->themesRoot]);
});

afterEach(function (): void {
    (new Filesystem)->remove($this->themesRoot);
});

function installCompatTheme(array $overrides): string
{
    $dir = config('magna.themes_path').'/acme/aurora';
    (new Filesystem)->mkdir($dir);
    file_put_contents($dir.'/theme.json', json_encode(array_merge([
        'name' => 'acme/aurora',
        'displayName' => 'Aurora',
        'version' => '1.0.0',
    ], $overrides)));

    return $dir;
}

function enablePlugin(string $name, string $version): void
{
    PluginRecord::create([
        'name' => $name,
        'display_name' => $name,
        'version' => $version,
        'enabled' => true,
        'base_path' => base_path('plugins-dev/'.$name),
        'manifest' => ['name' => $name],
    ]);
}

it('parses compat.plugins and drops what no floor could be read from', function (): void {
    $manifest = ThemeManifest::fromArray([
        'name' => 'acme/aurora',
        'version' => '1.0.0',
        'compat' => [
            'magna' => '^1.5',
            'plugins' => [
                'magna/pages' => '^0.3',
                // Each of these is an author's typo, not a floor: a package
                // name no plugin can have, and a constraint that is not a
                // string. A floor nobody can parse must not become a floor
                // nobody can satisfy.
                'Not A Package' => '^1.0',
                'magna/forms' => '',
                'magna/seo' => ['^1.0'],
            ],
        ],
    ]);

    expect($manifest->magnaCompat)->toBe('^1.5')
        ->and($manifest->pluginCompat)->toBe(['magna/pages' => '^0.3']);
});

it('declares no plugin floor when the manifest names none', function (): void {
    $manifest = ThemeManifest::fromArray(['name' => 'acme/aurora', 'version' => '1.0.0']);

    expect($manifest->pluginCompat)->toBe([]);
});

it('separates a plugin that is too old from one that is not there', function (): void {
    $manifest = ThemeManifest::fromArray([
        'name' => 'acme/aurora',
        'version' => '1.0.0',
        'compat' => ['plugins' => ['magna/pages' => '^0.3', 'magna/forms' => '^1.0']],
    ]);

    // Both fail the floor, and both need a different remedy — update one,
    // install the other — so the caller is told which it was.
    expect($manifest->unmetPluginCompat(['magna/pages' => '0.2.1-alpha']))
        ->toBe([
            'magna/pages' => ['required' => '^0.3', 'found' => '0.2.1-alpha'],
            'magna/forms' => ['required' => '^1.0', 'found' => null],
        ]);
});

it('treats a version it cannot parse as unmet', function (): void {
    $manifest = ThemeManifest::fromArray([
        'name' => 'acme/aurora',
        'version' => '1.0.0',
        'compat' => ['plugins' => ['magna/pages' => '^0.3']],
    ]);

    // Unverifiable is not satisfied: a version this cannot read is precisely
    // the case where the theme's assumptions cannot be confirmed.
    expect($manifest->unmetPluginCompat(['magna/pages' => 'dev-main']))
        ->toHaveKey('magna/pages');
});

it('activates when the floor is met', function (): void {
    installCompatTheme(['compat' => ['magna' => '^1.0', 'plugins' => ['magna/pages' => '^0.3']]]);
    enablePlugin('magna/pages', '0.3.2');

    $theme = app(ThemeManager::class)->activate('acme/aurora');

    expect($theme->name)->toBe('acme/aurora');
});

it('refuses activation when a plugin is older than the floor, and says both versions', function (): void {
    installCompatTheme(['compat' => ['magna' => '^1.0', 'plugins' => ['magna/pages' => '^0.3']]]);
    enablePlugin('magna/pages', '0.2.1-alpha');

    expect(fn () => app(ThemeManager::class)->activate('acme/aurora'))
        ->toThrow(InvalidThemeException::class, 'magna/pages ^0.3 (this site has 0.2.1-alpha)');
});

it('does not count an installed-but-disabled plugin as satisfying a floor', function (): void {
    installCompatTheme(['compat' => ['magna' => '^1.0', 'plugins' => ['magna/pages' => '^0.3']]]);

    PluginRecord::create([
        'name' => 'magna/pages',
        'display_name' => 'magna/pages',
        'version' => '0.3.2',
        // Disabled: the plugin that would do the rendering is not running, so
        // the page is as broken as if the plugin were absent — and counting
        // it would pass the check while producing exactly the failure the
        // check exists for.
        'enabled' => false,
        'base_path' => base_path('plugins-dev/magna/pages'),
        'manifest' => ['name' => 'magna/pages'],
    ]);

    expect(fn () => app(ThemeManager::class)->activate('acme/aurora'))
        ->toThrow(InvalidThemeException::class, 'not enabled here');
});

it('leaves the floor unchecked when the plugin store cannot be read', function (): void {
    installCompatTheme(['compat' => ['magna' => '^1.0', 'plugins' => ['magna/pages' => '^9.0']]]);

    // The plugins table does not exist before the installer has run, and a
    // theme IS activated during installation. Refusing on unknown state would
    // leave a site unable to activate its own theme with no way back — worse
    // than the layout bug the floor guards against.
    $unreadable = new class extends EnabledPluginVersions
    {
        public function all(): ?array
        {
            return null;
        }
    };

    $manager = new ThemeManager(new Filesystem, $unreadable);

    expect($manager->activate('acme/aurora')->name)->toBe('acme/aurora');
});

it('reports every unmet floor at once, not the first one', function (): void {
    installCompatTheme([
        'compat' => ['magna' => '^1.0', 'plugins' => ['magna/pages' => '^0.3', 'magna/forms' => '^1.0']],
    ]);
    enablePlugin('magna/pages', '0.2.1-alpha');

    // An admin who has to discover these one activation at a time will stop
    // before the end of the list.
    expect(fn () => app(ThemeManager::class)->activate('acme/aurora'))
        ->toThrow(InvalidThemeException::class, 'magna/forms ^1.0 (not enabled here)');
});
