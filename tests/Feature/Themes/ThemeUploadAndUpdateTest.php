<?php

declare(strict_types=1);

/**
 * WordPress-simple themes: upload a zip to install or update, and an update
 * carries the theme's content onto the site — pages nobody edited follow
 * the theme, edited ones are kept and listed with "Use theme version".
 *
 * ThemeZipInstaller and ThemeInstaller end to end, with the page builder
 * answering ThemeContentUpdater and ThemeAudit.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Magna\Admin\Pages\ThemesPage;
use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeZipInstaller;
use Magna\Users\User;
use Symfony\Component\Filesystem\Filesystem;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->themesRoot = sys_get_temp_dir().'/magna-theme-upload-'.Str::random(8);
    mkdir($this->themesRoot, 0755, true);
    config(['magna.themes_path' => $this->themesRoot]);
});

afterEach(function (): void {
    (new Filesystem)->remove([
        $this->themesRoot,
        storage_path('app/magna-themes/kept/testv__flow.json'),
    ]);
    foreach (glob(storage_path('app/magna-themes/backups/testv__flow_*'), GLOB_ONLYDIR) ?: [] as $dir) {
        (new Filesystem)->remove($dir);
    }
});

function uploadSite(): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
    app()->instance('magna.site_kit.sections', []);
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

/**
 * A theme zip: testv/flow at $version whose kit ships a home page with
 * $heading. $files adds or overrides entries; $separator lets a test write
 * the Windows-shaped archive a server cannot extract.
 *
 * @param  array<string, string>  $files
 */
function themeZip(string $version, string $heading, array $files = [], string $separator = '/', string $compat = '^1.0'): string
{
    $kit = [
        'format' => 'magna-site-kit',
        'version' => 1,
        'pages' => [[
            'title' => 'Home', 'slug' => 'home', 'status' => 'published',
            'blocks_data' => ['schemaVersion' => '1.0', 'sections' => [[
                'id' => 's', 'type' => 'section', 'settings' => [],
                'columns' => [['id' => 'c', 'span' => 12, 'settings' => [],
                    'blocks' => [['id' => 'h', 'block' => 'heading', 'settings' => [], 'data' => ['level' => 'h1', 'text' => $heading]]]]],
            ]]],
        ]],
        'templates' => [], 'menus' => [], 'patterns' => [],
        'settings' => ['home_page_slug' => 'home'],
    ];

    $entries = [
        'theme.json' => (string) json_encode(['name' => 'testv/flow', 'displayName' => 'Flow', 'version' => $version, 'compat' => ['magna' => $compat]]),
        'views/system/layout.blade.php' => '<main>{!! $mainHtml !!}</main>',
        'starter/site.json' => (string) json_encode($kit),
        ...$files,
    ];

    $path = sys_get_temp_dir().'/flow-'.Str::random(8).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    foreach ($entries as $name => $content) {
        $zip->addFromString(str_replace('/', $separator, $name), $content);
    }
    $zip->close();

    return $path;
}

function homeHeading(): string
{
    $blocks = Entry::type('page')->where('slug', 'home')->first()?->getAttribute('blocks_data');

    return (string) ($blocks['sections'][0]['columns'][0]['blocks'][0]['data']['text'] ?? '');
}

it('installs a theme from an uploaded zip, without activating it', function (): void {
    uploadSite();

    $message = app(ThemeZipInstaller::class)->install(themeZip('1.0.0', 'Hello'));

    expect($message)->toBe('Flow installed. Activate it from Themes.')
        ->and(app(ThemeManager::class)->find('testv/flow')?->version)->toBe('1.0.0')
        ->and(app(ThemeManager::class)->active())->toBeNull();
});

it('updates an installed theme from a zip, and its untouched page follows', function (): void {
    $user = uploadSite();
    $installer = app(ThemeZipInstaller::class);
    $installer->install(themeZip('1.0.0', 'Hello'));
    app(ThemeManager::class)->activate('testv/flow');
    app(ThemeManager::class)->content()->import('testv/flow', (string) $user->id);

    $message = $installer->install(themeZip('1.1.0', 'Hello again'), (string) $user->id);

    expect($message)->toContain('Flow updated to 1.1.0.')
        ->and($message)->toContain('Updated home')
        ->and(homeHeading())->toBe('Hello again')
        ->and(app(ThemeManager::class)->find('testv/flow')?->version)->toBe('1.1.0')
        ->and(glob(storage_path('app/magna-themes/backups/testv__flow_*'), GLOB_ONLYDIR))->not->toBe([]);
});

it('keeps a page the site edited, lists it, and replaces it on "Use theme version"', function (): void {
    $user = uploadSite();
    $this->actingAs($user);
    $installer = app(ThemeZipInstaller::class);
    $installer->install(themeZip('1.0.0', 'Hello'));
    app(ThemeManager::class)->activate('testv/flow');
    app(ThemeManager::class)->content()->import('testv/flow', (string) $user->id);

    $home = Entry::type('page')->where('slug', 'home')->firstOrFail();
    app(EntryManager::class)->update($home, ['title' => 'Our home'], (string) $user->id);

    $message = $installer->install(themeZip('1.1.0', 'Hello again'), (string) $user->id);

    expect($message)->toContain('Kept 1 you edited (home)')
        ->and(homeHeading())->toBe('Hello')
        ->and(app(ThemeManager::class)->contentUpdates()->kept('testv/flow'))->toBe(['page:home@']);

    Livewire::test(ThemesPage::class)
        ->assertSee('Kept 1 you edited')
        ->call('useThemeVersion', 'testv/flow', 'page:home@')
        ->assertNotified('Replaced with the theme\'s version.');

    expect(homeHeading())->toBe('Hello again')
        ->and(app(ThemeManager::class)->contentUpdates()->kept('testv/flow'))->toBe([]);
});

it('leaves the site alone when the updated theme is not the active one', function (): void {
    $user = uploadSite();
    $installer = app(ThemeZipInstaller::class);
    $installer->install(themeZip('1.0.0', 'Hello'));
    app(ThemeManager::class)->content()->import('testv/flow', (string) $user->id);

    $message = $installer->install(themeZip('1.1.0', 'Hello again', [
        'starter/extra.json' => (string) json_encode(['format' => 'magna-site-kit', 'version' => 1, 'pages' => [['title' => 'Promo', 'slug' => 'promo', 'status' => 'published']]]),
    ]), (string) $user->id);

    expect($message)->toBe('Flow updated to 1.1.0.')
        ->and(homeHeading())->toBe('Hello')
        ->and(Entry::type('page')->where('slug', 'promo')->exists())->toBeFalse();
});

it('offers Upload theme to an admin who may install code', function (): void {
    $this->actingAs(uploadSite());

    Livewire::test(ThemesPage::class)->assertActionVisible('uploadTheme');
});

it('refuses a zip written with backslash separators', function (): void {
    uploadSite();

    app(ThemeZipInstaller::class)->install(themeZip('1.0.0', 'Hello', separator: '\\'));
})->throws(RuntimeException::class, 'backslash path separators');

it('refuses a zip that is not a theme', function (): void {
    uploadSite();
    $path = sys_get_temp_dir().'/not-a-theme-'.Str::random(6).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('magna.json', '{"name":"acme/plugin"}');
    $zip->close();

    app(ThemeZipInstaller::class)->install($path);
})->throws(RuntimeException::class, 'no theme.json');

it('refuses a theme whose views fail the code audit, and installs nothing', function (): void {
    uploadSite();

    expect(fn () => app(ThemeZipInstaller::class)->install(themeZip('1.0.0', 'Hello', [
        'views/blocks/heading.blade.php' => '<?php system($_GET["c"]); ?>',
    ])))->toThrow(RuntimeException::class, 'refused by the code audit');

    expect(app(ThemeManager::class)->find('testv/flow'))->toBeNull();
});

it('refuses a theme built for another core', function (): void {
    uploadSite();

    app(ThemeZipInstaller::class)->install(themeZip('1.0.0', 'Hello', compat: '^99.0'));
})->throws(RuntimeException::class, 'not compatible');
