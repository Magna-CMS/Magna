<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Themes\InvalidThemeException;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeSettings;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * ThemeManager had zero tests while pathFor() joined a name onto the themes
 * root and remove() handed the result to a recursive delete. The fence has
 * two layers — ThemeManifest::fromArray validates the name at parse, and
 * installed() refuses a manifest whose name does not realpath-match its
 * directory — and every layer is pinned here, in an isolated themes root.
 */
beforeEach(function (): void {
    $this->themesRoot = storage_path('framework/testing/themes-'.uniqid());
    config(['magna.themes_path' => $this->themesRoot]);
});

afterEach(function (): void {
    (new Filesystem)->remove($this->themesRoot);
});

function installTheme(string $name, array $overrides = []): string
{
    $dir = config('magna.themes_path').'/'.$name;
    (new Filesystem)->mkdir($dir);
    file_put_contents($dir.'/theme.json', json_encode(array_merge([
        'name' => $name,
        'version' => '1.0.0',
    ], $overrides)));

    return $dir;
}

it('refuses to build a path from a name no manifest could carry', function (string $name): void {
    expect(fn () => app(ThemeManager::class)->pathFor($name))
        ->toThrow(InvalidThemeException::class);
})->with([
    'traversal' => ['../../etc'],
    'traversal inside a vendor' => ['acme/../../../etc'],
    'absolute-ish' => ['/etc/passwd'],
    'no slash' => ['standalone'],
    'extra segment' => ['a/b/c'],
    'uppercase' => ['Acme/Aurora'],
    'empty' => [''],
]);

it('lists installed themes and skips a malformed manifest without hiding the rest', function (): void {
    installTheme('acme/aurora');

    // One bad theme must not hide the rest from the admin who came to
    // remove it — the documented contract of installed().
    $bad = config('magna.themes_path').'/acme/broken';
    (new Filesystem)->mkdir($bad);
    file_put_contents($bad.'/theme.json', '{not json');

    $installed = app(ThemeManager::class)->installed();

    expect(array_keys($installed))->toBe(['acme/aurora']);
});

it('skips a manifest whose name does not match the directory it sits in', function (): void {
    // A package sitting in someone else's directory is not trusted to be
    // it — this realpath fence is what makes find()/remove() traversal-proof
    // even though the name in the file is attacker-authored.
    installTheme('acme/aurora', ['name' => 'evil/imposter']);

    expect(app(ThemeManager::class)->installed())->toBe([]);
});

it('activates an installed theme and deactivates it again', function (): void {
    installTheme('acme/aurora');

    $manager = app(ThemeManager::class);
    $manager->activate('acme/aurora');

    expect(ThemeSettings::get()->active)->toBe('acme/aurora')
        ->and($manager->active()?->name)->toBe('acme/aurora');

    $manager->deactivate();

    expect($manager->active())->toBeNull();
});

it('refuses to activate a theme that is not installed', function (): void {
    expect(fn () => app(ThemeManager::class)->activate('acme/ghost'))
        ->toThrow(InvalidThemeException::class, 'not installed');
});

it('refuses to activate an incompatible theme', function (): void {
    installTheme('acme/aurora', ['compat' => ['magna' => '^99.0']]);

    expect(fn () => app(ThemeManager::class)->activate('acme/aurora'))
        ->toThrow(InvalidThemeException::class, 'not compatible');
});

it('removes exactly the named theme and deactivates it when it was active', function (): void {
    $auroraDir = installTheme('acme/aurora');
    $keptDir = installTheme('acme/other');

    $manager = app(ThemeManager::class);
    $manager->activate('acme/aurora');

    $manager->remove('acme/aurora');

    expect(is_dir($auroraDir))->toBeFalse()
        ->and(is_dir($keptDir))->toBeTrue()
        ->and(ThemeSettings::get()->active)->toBeNull();
});
