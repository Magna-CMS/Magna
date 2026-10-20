<?php

declare(strict_types=1);

/**
 * Redirects under locale prefixes (Pages 0.5.0). Until now the router looked
 * redirects up by the raw request path with locale '' — but a translated
 * page's rename is recorded under its locale with the prefix stripped, so
 * no redirect outside the fallback locale ever matched. Lookup order: the
 * raw path (locale ''), then the localized path in the request's locale,
 * then the localized path locale-less; Location keeps the prefix.
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Pages\Routing\PageRedirect;
use Magna\Plugins\PluginManager;
use Magna\Settings\LocalizationSettings;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function localeRedirectSetup(): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    $settings = LocalizationSettings::get();
    $settings->available_locales = ['en', 'fr'];
    $settings->fallback_locale = 'en';
    app(SettingsRepository::class)->persist($settings);

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

function localeRedirectRow(string $source, string $target, string $locale): void
{
    PageRedirect::query()->create([
        'source_path' => $source, 'target_path' => $target,
        'status' => 301, 'locale' => $locale, 'automatic' => false,
    ]);
}

it('redirects a renamed translation under its locale prefix', function (): void {
    $author = localeRedirectSetup();
    $manager = app(EntryManager::class);

    $en = $manager->publish($manager->create('page', [
        'title' => 'Prices', 'slug' => 'prices', 'blocks_data' => [],
    ], $author->id), actorId: $author->id);
    $fr = $manager->createTranslation($en, 'fr', $author->id);
    $fr = $manager->publish($manager->update($fr, ['title' => 'Tarifs', 'slug' => 'tarifs'], $author->id), actorId: $author->id);

    $manager->update($fr, ['slug' => 'nos-tarifs'], $author->id);

    expect(PageRedirect::query()->where('locale', 'fr')->where('source_path', 'tarifs')->value('target_path'))
        ->toBe('nos-tarifs');

    $this->get('/fr/tarifs')
        ->assertStatus(301)
        ->assertHeader('Location', url('/fr/nos-tarifs'));
});

it('applies a locale-less redirect under every locale prefix', function (): void {
    localeRedirectSetup();
    localeRedirectRow('old-guide', 'new-guide', '');

    $this->get('/old-guide')->assertStatus(301)->assertHeader('Location', url('/new-guide'));
    $this->get('/fr/old-guide')->assertStatus(301)->assertHeader('Location', url('/fr/new-guide'));
});

it('matches a full-path redirect before the localized one', function (): void {
    localeRedirectSetup();
    localeRedirectRow('fr/legacy', 'fr/current', '');
    localeRedirectRow('legacy', 'wrong-target', 'fr');

    $this->get('/fr/legacy')->assertStatus(301)->assertHeader('Location', url('/fr/current'));
});

it('matches a fallback-locale redirect recorded under the locale code', function (): void {
    localeRedirectSetup();
    localeRedirectRow('ancien', 'nouveau', 'en');
    localeRedirectRow('only-french', 'elsewhere', 'fr');

    $this->get('/ancien')->assertStatus(301)->assertHeader('Location', url('/nouveau'));

    // A French redirect never fires on the bare (English) path.
    $this->get('/only-french')->assertNotFound();
});
