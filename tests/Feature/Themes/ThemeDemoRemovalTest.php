<?php

declare(strict_types=1);

/**
 * Taking a theme's demo content back out, as core drives it: when the
 * Themes page offers it, the order it runs in, and that the theme's files
 * are still there when it does. What counts as "untouched" is the page
 * builder's business (ThemeContentRemover) and is tested with the real
 * plugins in tests/Feature/Plugins/PagesThemeDemoRemovalTest.php; here a
 * fake stands in, recording what it was asked and when.
 */

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Magna\Admin\Pages\ThemesPage;
use Magna\Auth\Role;
use Magna\Themes\ThemeContent;
use Magna\Themes\ThemeContentRemover;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeSettings;
use Magna\Users\User;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

final class FakeThemeDemoRemover implements ThemeContentRemover
{
    /** @var list<string> */
    public static array $calls = [];

    public static bool $onSite = true;

    public function preview(array $kit): array
    {
        return self::$onSite
            ? ['remove' => [['key' => 'page:about@', 'label' => 'about']], 'keep' => [['key' => 'page:home@', 'label' => 'home', 'reason' => 'you changed it']]]
            : ['remove' => [], 'keep' => []];
    }

    public function removeUntouched(array $kit, ?string $actorId = null): array
    {
        $active = ThemeSettings::get()->active;
        $themes = app(ThemeManager::class);
        self::$calls[] = 'remove while active='.($active ?? 'none').' files='.(is_dir($themes->directory().'/testv/demo') ? 'present' : 'gone');
        self::$onSite = false;

        return ['pages' => 1, 'kept' => 1];
    }
}

beforeEach(function (): void {
    Http::fake();
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    // A writable copy: removing a theme deletes its directory.
    $this->themesRoot = sys_get_temp_dir().'/magna-demo-removal-'.bin2hex(random_bytes(4));
    $files = new Filesystem;
    $files->mirror(dirname(__DIR__, 2).'/Fixtures/demo-themes', $this->themesRoot);
    $files->mirror($this->themesRoot.'/testv/demo', $this->themesRoot.'/testv/next');
    $manifest = json_decode((string) file_get_contents($this->themesRoot.'/testv/next/theme.json'), true);
    $manifest['name'] = 'testv/next';
    $manifest['displayName'] = 'Next Theme';
    file_put_contents($this->themesRoot.'/testv/next/theme.json', json_encode($manifest));
    config(['magna.themes_path' => $this->themesRoot]);

    FakeThemeDemoRemover::$calls = [];
    FakeThemeDemoRemover::$onSite = true;

    $role = Role::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);
    $this->actingAs($user);
});

afterEach(function (): void {
    (new Filesystem)->remove($this->themesRoot);
});

function bindFakeDemoRemover(): void
{
    app()->bind(ThemeContentRemover::class, FakeThemeDemoRemover::class);
}

it('offers no removal when no plugin can remove demo content', function (): void {
    Livewire::test(ThemesPage::class)
        ->assertDontSee('Remove demo content')
        ->call('askRemoveDemo', 'testv/demo')
        ->assertNotified('Could not read the demo content');
});

it('offers removal only for a theme whose demo content is on the site', function (): void {
    bindFakeDemoRemover();

    Livewire::test(ThemesPage::class)->assertSee('Remove demo content');

    FakeThemeDemoRemover::$onSite = false;
    cache()->flush();

    Livewire::test(ThemesPage::class)->assertDontSee('Remove demo content');
});

it('shows what goes and what stays before removing anything', function (): void {
    bindFakeDemoRemover();

    Livewire::test(ThemesPage::class)
        ->call('askRemoveDemo', 'testv/demo')
        ->assertSet('demoMode', 'remove-demo')
        ->assertSee('Removed (1)')
        ->assertSee('Kept (1)')
        ->assertSee('you changed it')
        ->call('confirmDemoDialog')
        ->assertNotified('Demo content removed')
        ->assertSet('demoMode', null);

    expect(FakeThemeDemoRemover::$calls)->toHaveCount(1)
        ->and(ThemeContent::removalSummary(['pages' => 1, 'kept' => 1]))->toBe('Removed 1 page — 1 kept because you changed them or they are still in use.');
});

it('removes the demo content while the theme files are still there, then the theme', function (): void {
    bindFakeDemoRemover();

    Livewire::test(ThemesPage::class)
        ->call('askRemoveTheme', 'testv/demo')
        ->assertSet('demoMode', 'remove-theme')
        ->set('demoRemove', true)
        ->call('confirmDemoDialog')
        ->assertNotified('Theme removed.');

    expect(FakeThemeDemoRemover::$calls)->toBe(['remove while active=none files=present'])
        ->and(app(ThemeManager::class)->find('testv/demo'))->toBeNull();
});

it('removes only the theme when the box is left unticked', function (): void {
    bindFakeDemoRemover();

    Livewire::test(ThemesPage::class)
        ->call('askRemoveTheme', 'testv/demo')
        ->call('confirmDemoDialog');

    expect(FakeThemeDemoRemover::$calls)->toBe([])
        ->and(app(ThemeManager::class)->find('testv/demo'))->toBeNull();
});

it('removes the old demo before switching, so the new theme takes over the slugs', function (): void {
    bindFakeDemoRemover();
    app(ThemeManager::class)->activate('testv/demo');

    Livewire::test(ThemesPage::class)
        ->call('askActivate', 'testv/next')
        ->assertSet('demoMode', 'switch')
        ->assertSee('Switch to Next Theme')
        ->set('demoRemove', true)
        ->call('confirmDemoDialog')
        ->assertNotified('Next Theme is now the active theme.');

    expect(FakeThemeDemoRemover::$calls)->toBe(['remove while active=testv/demo files=present'])
        ->and(ThemeSettings::get()->active)->toBe('testv/next');
});

it('switches straight away when the active theme has no demo content on the site', function (): void {
    bindFakeDemoRemover();
    FakeThemeDemoRemover::$onSite = false;
    app(ThemeManager::class)->activate('testv/demo');

    Livewire::test(ThemesPage::class)
        ->call('askActivate', 'testv/next')
        ->assertSet('demoMode', null);

    expect(ThemeSettings::get()->active)->toBe('testv/next')
        ->and(FakeThemeDemoRemover::$calls)->toBe([]);
});

it('does not let the dialog be pointed at another theme from the browser', function (): void {
    bindFakeDemoRemover();

    Livewire::test(ThemesPage::class)
        ->call('askRemoveDemo', 'testv/demo')
        ->set('demoTheme', 'testv/next');
})->throws(CannotUpdateLockedPropertyException::class);
