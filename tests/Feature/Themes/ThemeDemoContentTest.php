<?php

declare(strict_types=1);

/**
 * Installing a theme with demo data, end to end: core's ThemeContent finds
 * the theme's declared demo bundles, the page builder imports them adding
 * only what is missing, and the Blog's 'blog:posts' section brings the posts.
 * From the Themes page that is the Installed card's "Import demo content"
 * button and "Include demo content" ticked beside an install button.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Admin\Pages\ThemesPage;
use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Licensing\LicenseClient;
use Magna\Licensing\LicenseInstaller;
use Magna\Pages\PagesSettings;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeContent;
use Magna\Themes\ThemeManager;
use Magna\Users\User;
use MagnaCms\Blog\Models\Post;

uses(PluginTestCase::class);

function demoThemeSite(bool $blog = true): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
    if ($blog) {
        skipWithoutDevPlugin('magna-cms/blog');
        test()->enablePlugin('magna-cms/blog');
    }

    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/demo-themes']);
    Storage::fake('public');
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('finds only the bundles a theme declares, inside its own starter folder', function (): void {
    demoThemeSite();

    $bundles = app(ThemeManager::class)->content()->bundles('testv/demo');

    // starter/optional.json is not declared; starter/../theme.json escapes.
    expect(array_map('basename', $bundles))->toBe(['demo.json']);
});

it('imports pages, menus, settings and posts on an empty site', function (): void {
    $user = demoThemeSite();

    $totals = app(ThemeManager::class)->content()->import('testv/demo', (string) $user->id);

    $post = Post::query()->firstWhere('slug', 'demo-first-post');

    expect($totals['created'])->toBe(2)
        ->and($totals['menus'])->toBe(1)
        ->and($totals['blog:posts'])->toBe(2)
        ->and(Entry::type('page')->where('slug', 'optional-only')->exists())->toBeFalse()
        ->and(PagesSettings::get()->home_page_id)->toBe((string) Entry::type('page')->where('slug', 'demo-home')->value('id'))
        ->and($post->is_featured)->toBeTrue()
        ->and($post->author_id)->toBe($user->id)
        ->and($post->category->slug)->toBe('demo')
        ->and($post->featured_image)->not->toBeNull()
        ->and(ThemeContent::summary($totals))->toBe('Added 2 pages, 1 menu, 2 posts.');
});

it('leaves everything the site already has untouched, and says so', function (): void {
    $user = demoThemeSite();
    $about = app(EntryManager::class)->create('page', ['title' => 'Our about', 'slug' => 'about', 'blocks_data' => []], $user->id);
    Post::query()->create(['title' => 'My own words', 'slug' => 'demo-first-post', 'locale' => 'en', 'content' => ['blocks' => []], 'status' => 'published']);

    $totals = app(ThemeManager::class)->content()->import('testv/demo', (string) $user->id);

    expect(Entry::type('page')->whereKey($about->getKey())->value('title'))->toBe('Our about')
        ->and(Post::query()->firstWhere('slug', 'demo-first-post')->title)->toBe('My own words')
        ->and($totals['created'])->toBe(1)
        ->and($totals['blog:posts'])->toBe(1)
        ->and($totals['skipped'])->toBe(2)
        ->and(ThemeContent::summary($totals))->toContain('2 already on your site, left unchanged');
});

it('imports twice without duplicating anything', function (): void {
    $user = demoThemeSite();
    $content = app(ThemeManager::class)->content();

    $content->import('testv/demo', (string) $user->id);
    $second = $content->import('testv/demo', (string) $user->id);

    expect(Entry::type('page')->where('slug', 'about')->count())->toBe(1)
        ->and(Post::query()->count())->toBe(2)
        ->and(ThemeContent::summary($second))->toStartWith('Nothing new to add');
});

it('offers and runs "Import demo content" on the Installed card', function (): void {
    $this->actingAs(demoThemeSite());

    Livewire::test(ThemesPage::class)
        ->assertSee('Import demo content')
        ->call('importDemoContent', 'testv/demo')
        ->assertNotified('Demo content imported');

    expect(Post::query()->count())->toBe(2);
});

it('imports demo content after an install when "Include demo content" was ticked', function (): void {
    $this->actingAs(demoThemeSite());

    $settings = AccountCentreSettings::get();
    $settings->connected = true;
    $settings->token = 'site-account-token';
    $settings->save();

    // The theme is already on disk (the fixture), so the "install" is mocked
    // and what is under test is what happens after it.
    $client = $this->mock(LicenseClient::class);
    $client->shouldReceive('claimFree')->andReturn(['ok' => true, 'license' => ['id' => 5, 'product_slug' => 'testv/demo']]);
    $client->shouldReceive('forgetCache');
    $this->mock(LicenseInstaller::class)->shouldReceive('installLicense')->with(5, 'testv/demo')->andReturn('Demo Theme installed.');

    Livewire::test(ThemesPage::class)
        ->set('withDemo.'.ThemesPage::demoKey('testv/demo'), true)
        ->call('getFree', 'testv/demo')
        ->assertNotified('Demo Theme installed.')
        ->assertSet('withDemo', []);

    expect(Post::query()->count())->toBe(2)
        ->and(Entry::type('page')->where('slug', 'demo-home')->exists())->toBeTrue();
});

it('imports nothing after an install when the box was left unticked', function (): void {
    $this->actingAs(demoThemeSite());

    $settings = AccountCentreSettings::get();
    $settings->connected = true;
    $settings->token = 'site-account-token';
    $settings->save();

    $client = $this->mock(LicenseClient::class);
    $client->shouldReceive('claimFree')->andReturn(['ok' => true, 'license' => ['id' => 5]]);
    $client->shouldReceive('forgetCache');
    $this->mock(LicenseInstaller::class)->shouldReceive('installLicense')->andReturn('Demo Theme installed.');

    Livewire::test(ThemesPage::class)->call('getFree', 'testv/demo');

    expect(Post::query()->count())->toBe(0)
        ->and(Entry::type('page')->where('slug', 'demo-home')->exists())->toBeFalse();
});

it('offers no demo content when the page builder is not enabled', function (): void {
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/demo-themes']);

    $content = app(ThemeManager::class)->content();

    expect($content->supported())->toBeFalse()
        ->and($content->available('testv/demo'))->toBeFalse();

    $content->import('testv/demo');
})->throws(RuntimeException::class, 'Magna Pages');
