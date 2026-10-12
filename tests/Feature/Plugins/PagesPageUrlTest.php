<?php

declare(strict_types=1);

/**
 * The layout contract's pageUrl closure + the PageUrls resolver behind it:
 * a theme links any published page by slug instead of hardcoding a path,
 * and the link survives what a hardcoded one does not — a rename, `path`
 * nesting, a locale prefix. Null for a draft or unknown slug, so the
 * layout can degrade the link away.
 */

use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Routing\PageUrls;
use Magna\Plugins\PluginManager;
use Magna\Settings\LocalizationSettings;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function pageUrlSetup(): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    config()->set('magna.themes_path', base_path('tests/Fixtures/themes'));
    app(ThemeManager::class)->activate('testv/fixture');

    return User::factory()->create();
}

function publishedPage(User $author, string $title, string $slug, ?string $parentId = null): Entry
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => $title, 'slug' => $slug, 'parent_id' => $parentId,
        'blocks_data' => [],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('lets a theme layout link a page by slug through the pageUrl closure', function (): void {
    $author = pageUrlSetup();
    publishedPage($author, 'Download', 'download');
    publishedPage($author, 'Landing', 'landing');

    $html = (string) $this->get('/landing')->assertOk()->getContent();

    expect($html)->toContain('class="fixture-theme-cta" href="/download"');
});

it('resolves null for a draft or unknown slug, and the layout drops the link', function (): void {
    $author = pageUrlSetup();
    publishedPage($author, 'Landing', 'landing');

    // 'download' exists only as a draft — the closure must say null, not
    // guess an address a visitor would 404 on.
    app(EntryManager::class)->create('page', [
        'title' => 'Download', 'slug' => 'download', 'blocks_data' => [],
    ], $author->id);

    $html = (string) $this->get('/landing')->assertOk()->getContent();

    expect($html)->not->toContain('fixture-theme-cta')
        ->and(app(PageUrls::class)->forSlug('download'))->toBeNull()
        ->and(app(PageUrls::class)->forSlug('never-existed'))->toBeNull();
});

it('resolves the nested path, not the bare slug, for a child page', function (): void {
    $author = pageUrlSetup();
    $docs = publishedPage($author, 'Docs', 'docs');
    publishedPage($author, 'Download', 'download', (string) $docs->getKey());

    // rtrim($homeUrl,'/').'/download' — the derivation this closure
    // replaces — can never produce this address.
    expect(app(PageUrls::class)->forSlug('download'))->toBe('/docs/download');
});

it('prefixes a non-fallback-locale page and prefers the fallback translation on a shared slug', function (): void {
    $author = pageUrlSetup();

    $settings = LocalizationSettings::get();
    $settings->available_locales = ['en', 'fr'];
    $settings->fallback_locale = 'en';
    app(SettingsRepository::class)->persist($settings);

    $manager = app(EntryManager::class);

    $en = publishedPage($author, 'Pricing', 'pricing');
    $fr = $manager->createTranslation($en, 'fr', $author->id);
    $manager->publish($fr, actorId: $author->id);

    // Shared slug: the fallback locale's page wins — a layout link stays
    // stable whichever locale the visitor is reading.
    expect(app(PageUrls::class)->forSlug('pricing'))->toBe('/pricing');

    // A page published only in a non-fallback locale is only served under
    // its prefix, so that is the address the resolver gives.
    $soldes = $manager->create('page', [
        'title' => 'Soldes', 'slug' => 'soldes', 'locale' => 'fr', 'blocks_data' => [],
    ], $author->id);
    $manager->publish($soldes, actorId: $author->id);

    expect(app(PageUrls::class)->forSlug('soldes'))->toBe('/fr/soldes');
});
