<?php

declare(strict_types=1);

/**
 * The site-kit (Phase C item 9, §F): export the site as one bundle, diff
 * it against an environment, apply it transactionally. Cross-references
 * travel by slug (ids are environment facts); sync upserts only — local
 * rows absent from the kit are reported, never deleted.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\PagesSettings;
use Magna\Pages\SiteKit\SiteKit;
use Magna\Plugins\PluginManager;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function kitUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish', 'pages.settings');
    $user->assignRole($role);

    return $user;
}

function kitSeed(User $author): void
{
    $manager = app(EntryManager::class);

    $home = $manager->create('page', ['title' => 'Home', 'slug' => 'kit-home', 'blocks_data' => [[
        'id' => 'sec-kit-h', 'type' => 'section', 'settings' => [],
        'columns' => [['id' => 'col-kit-h', 'span' => 12, 'settings' => [],
            'blocks' => [['id' => 'blk-kit-h', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Welcome home']]]]],
    ]]], $author->id);
    $manager->publish($home, actorId: $author->id);

    $about = $manager->create('page', ['title' => 'About', 'slug' => 'kit-about', 'blocks_data' => []], $author->id);
    $manager->publish($about, actorId: $author->id);

    $menus = app(MenuManager::class);
    $menus->syncItems($menus->create('primary', 'Primary'), [
        ['label' => 'About', 'type' => 'page', 'page_id' => (string) $about->getKey()],
        ['label' => 'Docs', 'type' => 'url', 'url' => '/docs'],
    ]);

    $settings = PagesSettings::get();
    $settings->home_page_id = (string) $home->getKey();
    app(SettingsRepository::class)->persist($settings);
}

it('round-trips export, drift, diff, and sync', function (): void {
    $author = kitUser();
    kitSeed($author);
    $kit = app(SiteKit::class);

    $bundle = $kit->build();

    // Menus reference pages by slug in the bundle, never by id.
    expect(json_encode($bundle))->toContain('"page_slug":"kit-about"')
        ->and($bundle['settings']['home_page_slug'])->toBe('kit-home');

    // Nothing drifted yet: diff is quiet on entries.
    $clean = $kit->diff($bundle);
    expect($clean['pages']['added'])->toBe([])
        ->and($clean['pages']['changed'])->toBe([]);

    // Drift: retitle a page and delete another environment's-worth of state.
    $manager = app(EntryManager::class);
    /** @var Entry $about */
    $about = Entry::type('page')->where('slug', 'kit-about')->firstOrFail();
    $manager->update($about, ['title' => 'About (drifted)'], $author->id);

    $diff = $kit->diff($bundle);
    expect($diff['pages']['changed'])->toContain('kit-about@');

    // Sync restores the kit's state.
    $counts = $kit->apply($bundle, $author->id);
    expect($counts['updated'])->toBeGreaterThan(0);

    $restored = Entry::type('page')->where('slug', 'kit-about')->firstOrFail();
    expect($restored->getAttribute('title'))->toBe('About');
});

it('creates missing pages and remaps slug references on a fresh environment', function (): void {
    $author = kitUser();
    kitSeed($author);
    $bundle = app(SiteKit::class)->build();

    // Simulate the target environment: wipe pages, menus, settings.
    Entry::type('page')->delete();
    Menu::query()->delete();
    $settings = PagesSettings::get();
    $settings->home_page_id = null;
    app(SettingsRepository::class)->persist($settings);

    $counts = app(SiteKit::class)->apply($bundle, $author->id);
    expect($counts['created'])->toBe(2)
        ->and($counts['menus'])->toBe(1);

    // The home-page reference resolved to the NEW environment's id.
    $newHome = Entry::type('page')->where('slug', 'kit-home')->firstOrFail();
    expect(PagesSettings::get()->home_page_id)->toBe($newHome->getKey());

    // Published state carried over; the site actually serves.
    $this->get('/kit-home')->assertOk()->assertSee('Welcome home');

    // The menu's page item resolved by slug against the new ids.
    $links = app(MenuManager::class)->resolve('primary');
    expect(array_column($links, 'label'))->toContain('About');
});

it('leaves local-only rows alone and reports them in the diff', function (): void {
    $author = kitUser();
    kitSeed($author);
    $bundle = app(SiteKit::class)->build();

    // A page that exists locally but not in the kit.
    $manager = app(EntryManager::class);
    $local = $manager->create('page', ['title' => 'Local only', 'slug' => 'kit-local-only', 'blocks_data' => []], $author->id);
    $manager->publish($local, actorId: $author->id);

    $diff = app(SiteKit::class)->diff($bundle);
    expect($diff['pages']['localOnly'])->toContain('kit-local-only@');

    app(SiteKit::class)->apply($bundle, $author->id);

    // Still here: sync never deletes.
    expect(Entry::type('page')->where('slug', 'kit-local-only')->exists())->toBeTrue();
});
