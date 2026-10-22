<?php

declare(strict_types=1);

/**
 * Theme demo content: SiteKit::applyMissing(), the page builder's
 * ThemeContentImporter. It adds only what a site lacks — an existing page,
 * menu, pattern, setting or plugin record is never changed, and maintenance
 * mode is never touched — because demo content arrives on sites that already
 * have content.
 */

use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Contracts\SiteKitSection;
use Magna\Pages\Builder\Pattern;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\PagesSettings;
use Magna\Pages\SiteKit\SiteKit;
use Magna\Pages\SiteKit\ThemeKitImporter;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeContentImporter;
use Magna\Users\User;

uses(PluginTestCase::class);

function demoKitSetup(array $sections = []): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
    app()->instance('magna.site_kit.sections', $sections);

    return User::factory()->create();
}

function demoKit(array $extra = []): array
{
    $section = fn (string $id, string $text): array => [[
        'id' => $id, 'type' => 'section', 'settings' => [],
        'columns' => [['id' => $id.'-c', 'span' => 12, 'settings' => [],
            'blocks' => [['id' => $id.'-b', 'block' => 'heading', 'settings' => [], 'data' => ['text' => $text]]]]],
    ]];

    return [
        'format' => 'magna-site-kit',
        'version' => SiteKit::VERSION,
        'pages' => [
            ['title' => 'Demo home', 'slug' => 'home', 'status' => 'published', 'blocks_data' => $section('demo-h', 'Demo home')],
            ['title' => 'Demo about', 'slug' => 'about', 'status' => 'published', 'blocks_data' => $section('demo-a', 'Demo about')],
        ],
        'templates' => [],
        'menus' => [
            ['handle' => 'primary', 'name' => 'Primary', 'tree' => [['label' => 'About', 'type' => 'page', 'page_slug' => 'about', 'children' => []]]],
            ['handle' => 'footer', 'name' => 'Footer', 'tree' => [['label' => 'Home', 'type' => 'url', 'url' => '/', 'children' => []]]],
        ],
        'patterns' => [
            ['name' => 'Demo band', 'kind' => 'section', 'document' => $section('demo-p', 'Band')[0]],
        ],
        'settings' => ['home_page_slug' => 'home', 'not_found_page_slug' => 'about', 'maintenance_mode' => false],
        ...$extra,
    ];
}

it('puts a theme\'s demo content on an empty site', function (): void {
    $user = demoKitSetup();

    $counts = app(SiteKit::class)->applyMissing(demoKit(), (string) $user->id);

    expect($counts['created'])->toBe(2)
        ->and($counts['menus'])->toBe(2)
        ->and($counts['patterns'])->toBe(1)
        ->and($counts['skipped'])->toBe(0)
        ->and(Entry::type('page')->where('slug', 'home')->first()?->isPublished())->toBeTrue()
        ->and(PagesSettings::get()->home_page_id)->toBe((string) Entry::type('page')->where('slug', 'home')->value('id'));
});

it('never changes a page, menu, pattern or setting the site already has', function (): void {
    $user = demoKitSetup();
    $entries = app(EntryManager::class);

    $mine = $entries->create('page', ['title' => 'My about', 'slug' => 'about', 'blocks_data' => []], $user->id);
    $entries->publish($mine, actorId: $user->id);
    $myHome = $entries->create('page', ['title' => 'My landing', 'slug' => 'landing', 'blocks_data' => []], $user->id);

    $menus = app(MenuManager::class);
    $menus->syncItems($menus->create('primary', 'Primary'), [['label' => 'Mine', 'type' => 'url', 'url' => '/mine']]);

    $settings = PagesSettings::get();
    $settings->home_page_id = (string) $myHome->getKey();
    $settings->maintenance_mode = true;
    $settings->save();

    Pattern::query()->create(['name' => 'Demo band', 'kind' => 'section', 'document' => ['id' => 'mine', 'type' => 'section', 'settings' => [], 'columns' => []]]);

    $counts = app(SiteKit::class)->applyMissing(demoKit(), (string) $user->id);

    // The free slug and the empty menu are filled; everything else is left as it was.
    expect($counts['created'])->toBe(1)
        ->and($counts['menus'])->toBe(1)
        ->and($counts['patterns'])->toBe(0)
        ->and($counts['skipped'])->toBe(2)
        ->and(Entry::type('page')->where('slug', 'about')->first()->getAttribute('title'))->toBe('My about')
        ->and(Menu::query()->where('handle', 'primary')->first()->items()->count())->toBe(1)
        ->and(Menu::query()->where('handle', 'footer')->first()->items()->count())->toBe(1)
        ->and(PagesSettings::get()->home_page_id)->toBe((string) $myHome->getKey())
        ->and(PagesSettings::get()->maintenance_mode)->toBeTrue()
        ->and(Pattern::query()->where('name', 'Demo band')->first()->document['id'])->toBe('mine');
});

it('hands a plugin section only the rows it does not have yet', function (): void {
    $section = new class implements SiteKitSection
    {
        public array $applied = [];

        public function key(): string
        {
            return 'acme:things';
        }

        public function export(): array
        {
            return [['handle' => 'kept']];
        }

        public function diff(array $rows): array
        {
            $handles = array_map(fn (array $r): string => (string) ($r['handle'] ?? ''), $rows);

            return ['added' => array_values(array_diff($handles, ['kept'])), 'changed' => [], 'localOnly' => []];
        }

        public function apply(array $rows, ?string $actorId = null): int
        {
            $this->applied = $rows;

            return count($rows);
        }
    };
    $user = demoKitSetup([$section]);

    $counts = app(SiteKit::class)->applyMissing(demoKit(['acme:things' => [['handle' => 'kept'], ['handle' => 'new']]]), (string) $user->id);

    expect($section->applied)->toBe([['handle' => 'new']])
        ->and($counts['acme:things'])->toBe(1);
});

it('is what core gets when it asks for a theme content importer', function (): void {
    demoKitSetup();

    expect(app()->bound(ThemeContentImporter::class))->toBeTrue()
        ->and(app(ThemeContentImporter::class))->toBeInstanceOf(ThemeKitImporter::class);
});

it('refuses something that is not a site kit', function (): void {
    demoKitSetup();

    app(SiteKit::class)->applyMissing(['format' => 'nope']);
})->throws(InvalidArgumentException::class);
