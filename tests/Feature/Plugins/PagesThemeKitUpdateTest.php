<?php

declare(strict_types=1);

/**
 * Theme updates carry their content: ThemeKitUpdater, the page builder's
 * ThemeContentUpdater. An entry nobody edited follows the theme, an edited
 * one is kept and reported, and "Use theme version" replaces it with the
 * old version left restorable.
 */

use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\Models\Revision;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\PagesSettings;
use Magna\Pages\SiteKit\SiteKit;
use Magna\Pages\SiteKit\ThemeKitUpdater;
use Magna\Plugins\PluginManager;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeContentUpdater;
use Magna\Users\User;

uses(PluginTestCase::class);

function updateKitSetup(): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
    app()->instance('magna.site_kit.sections', []);

    return User::factory()->create();
}

/**
 * @param  array<string, string>  $headings  slug => heading text
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function themeKit(array $headings, string $menuLabel = 'About', array $extra = []): array
{
    $pages = [];
    foreach ($headings as $slug => $text) {
        $pages[] = [
            'title' => ucfirst($slug), 'slug' => $slug, 'status' => 'published',
            'blocks_data' => ['schemaVersion' => '1.0', 'sections' => [[
                'id' => $slug.'-s', 'type' => 'section', 'settings' => ['cssClass' => 'band'],
                'columns' => [['id' => $slug.'-c', 'span' => 12, 'settings' => [],
                    'blocks' => [
                        ['id' => $slug.'-h', 'block' => 'heading', 'settings' => [], 'data' => ['level' => 'h2', 'text' => $text, 'align' => 'left']],
                        ['id' => $slug.'-t', 'block' => 'text', 'settings' => [], 'data' => ['body' => '<p>About <strong>'.$text.'</strong></p>']],
                    ]]],
            ]]],
        ];
    }

    return [
        'format' => 'magna-site-kit',
        'version' => SiteKit::VERSION,
        'pages' => $pages,
        'templates' => [],
        'menus' => [
            ['handle' => 'primary', 'name' => 'Primary', 'tree' => [
                ['id' => 'mi-1', 'label' => $menuLabel, 'url' => '/about', 'target' => '', 'children' => []],
            ]],
        ],
        'patterns' => [],
        'settings' => ['home_page_slug' => 'home', 'not_found_page_slug' => 'about'],
        ...$extra,
    ];
}

function pageHeading(string $slug): string
{
    $blocks = Entry::type('page')->where('slug', $slug)->first()?->getAttribute('blocks_data');

    return (string) ($blocks['sections'][0]['columns'][0]['blocks'][0]['data']['text'] ?? '');
}

it('finds nothing to change on a site synced from the very same kit', function (): void {
    $user = updateKitSetup();
    $kit = themeKit(['home' => 'Welcome', 'about' => 'Who we are']);
    app(SiteKit::class)->apply($kit, (string) $user->id);

    // The database reorders jsonb keys and sanitises rich text; neither is
    // an edit. Before the comparison sorted every depth, this was 2 of 2.
    expect(app(SiteKit::class)->diff($kit)['pages']['changed'])->toBe([])
        ->and(app(ThemeKitUpdater::class)->update($kit, $kit, (string) $user->id))
        ->toBe(['updated' => [], 'added' => [], 'kept' => []]);
});

it('updates the pages nobody edited and adds the new ones', function (): void {
    $user = updateKitSetup();
    $old = themeKit(['home' => 'Welcome', 'about' => 'Who we are']);
    app(SiteKit::class)->apply($old, (string) $user->id);

    $new = themeKit(['home' => 'Welcome back', 'about' => 'Who we are', 'pricing' => 'Plans']);
    $report = app(ThemeKitUpdater::class)->update($old, $new, (string) $user->id);

    expect($report['updated'])->toBe(['page:home@'])
        ->and($report['added'])->toBe(['page:pricing@'])
        ->and($report['kept'])->toBe([])
        ->and(pageHeading('home'))->toBe('Welcome back')
        ->and(pageHeading('pricing'))->toBe('Plans')
        ->and(Entry::type('page')->where('slug', 'pricing')->first()?->isPublished())->toBeTrue();
});

it('keeps a page an editor changed, and says so', function (): void {
    $user = updateKitSetup();
    $old = themeKit(['home' => 'Welcome', 'about' => 'Who we are']);
    app(SiteKit::class)->apply($old, (string) $user->id);

    $home = Entry::type('page')->where('slug', 'home')->firstOrFail();
    app(EntryManager::class)->update($home, ['title' => 'Our own home'], (string) $user->id);

    $report = app(ThemeKitUpdater::class)->update($old, themeKit(['home' => 'Welcome back', 'about' => 'Who we are']), (string) $user->id);

    expect($report['kept'])->toBe(['page:home@'])
        ->and($report['updated'])->toBe([])
        ->and(Entry::type('page')->where('slug', 'home')->value('title'))->toBe('Our own home')
        ->and(pageHeading('home'))->toBe('Welcome');
});

it('keeps a page that differs when there is no outgoing kit to compare with', function (): void {
    $user = updateKitSetup();
    app(SiteKit::class)->apply(themeKit(['home' => 'Welcome']), (string) $user->id);

    $report = app(ThemeKitUpdater::class)->update([], themeKit(['home' => 'Welcome back']), (string) $user->id);

    expect($report['kept'])->toBe(['page:home@'])
        ->and(pageHeading('home'))->toBe('Welcome');
});

it('follows the theme for an untouched menu and keeps an edited one', function (): void {
    $user = updateKitSetup();
    $old = themeKit(['home' => 'Welcome', 'about' => 'Who we are']);
    app(SiteKit::class)->apply($old, (string) $user->id);

    $report = app(ThemeKitUpdater::class)->update($old, themeKit(['home' => 'Welcome', 'about' => 'Who we are'], 'About us'), (string) $user->id);
    $label = fn (): string => (string) Menu::query()->where('handle', 'primary')->first()?->items()->value('label');

    expect($report['updated'])->toBe(['menu:primary'])->and($label())->toBe('About us');

    $menu = Menu::query()->where('handle', 'primary')->firstOrFail();
    app(MenuManager::class)->syncItems($menu, [['label' => 'Our story', 'type' => 'url', 'url' => '/about']], (string) $user->id);

    $again = app(ThemeKitUpdater::class)->update(
        themeKit(['home' => 'Welcome', 'about' => 'Who we are'], 'About us'),
        themeKit(['home' => 'Welcome', 'about' => 'Who we are'], 'Company'),
        (string) $user->id,
    );

    expect($again['kept'])->toBe(['menu:primary'])->and($label())->toBe('Our story');
});

it('never touches maintenance mode, and moves the home page only if the site had not', function (): void {
    $user = updateKitSetup();
    $old = themeKit(['home' => 'Welcome', 'about' => 'Who we are', 'start' => 'Start']);
    app(SiteKit::class)->apply($old, (string) $user->id);

    $settings = PagesSettings::get();
    $settings->maintenance_mode = true;
    app(SettingsRepository::class)->persist($settings);

    $new = themeKit(['home' => 'Welcome', 'about' => 'Who we are', 'start' => 'Start']);
    $new['settings'] = ['home_page_slug' => 'start', 'not_found_page_slug' => 'about', 'maintenance_mode' => false];

    app(ThemeKitUpdater::class)->update($old, $new, (string) $user->id);

    expect(PagesSettings::get()->maintenance_mode)->toBeTrue()
        ->and(PagesSettings::get()->home_page_id)->toBe((string) Entry::type('page')->where('slug', 'start')->value('id'));
});

it('replaces a kept page with the theme version on request, leaving the old one restorable', function (): void {
    $user = updateKitSetup();
    $old = themeKit(['home' => 'Welcome']);
    app(SiteKit::class)->apply($old, (string) $user->id);

    $home = Entry::type('page')->where('slug', 'home')->firstOrFail();
    app(EntryManager::class)->update($home, ['title' => 'Our own home'], (string) $user->id);
    $revisionsBefore = Revision::query()->where('entry_id', $home->getKey())->count();

    $new = themeKit(['home' => 'Welcome back']);
    $report = app(ThemeKitUpdater::class)->update($old, $new, (string) $user->id);
    $replaced = app(ThemeKitUpdater::class)->replace($new, $report['kept'], (string) $user->id);

    expect($replaced)->toBe(1)
        ->and(pageHeading('home'))->toBe('Welcome back')
        ->and(Entry::type('page')->where('slug', 'home')->value('title'))->toBe('Home')
        ->and(Revision::query()->where('entry_id', $home->getKey())->count())->toBeGreaterThan($revisionsBefore);
});

it('is what core gets when it asks for a theme content updater', function (): void {
    updateKitSetup();

    expect(app(ThemeContentUpdater::class))->toBeInstanceOf(ThemeKitUpdater::class);
});

it('refuses something that is not a site kit', function (): void {
    $user = updateKitSetup();

    app(ThemeKitUpdater::class)->update([], ['format' => 'nope'], (string) $user->id);
})->throws(InvalidArgumentException::class);
