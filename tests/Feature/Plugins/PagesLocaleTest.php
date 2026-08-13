<?php

declare(strict_types=1);

/**
 * Locale-prefix routing + the locale switcher (§A2): the fallback locale
 * lives at bare paths, other available locales under their prefix; the
 * switcher block links a page's published translations and renders
 * nothing when there is nothing to switch to.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Settings\LocalizationSettings;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function localeUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

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

/** @return array{en: Entry, fr: Entry} */
function translatedPage(User $author, string $slug): array
{
    $manager = app(EntryManager::class);

    $document = [[
        'id' => 'sec-loc-'.$slug, 'type' => 'section', 'settings' => [],
        'columns' => [[
            'id' => 'col-loc-'.$slug, 'span' => 12, 'settings' => [],
            'blocks' => [
                ['id' => 'blk-loc-h-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'English body']],
                ['id' => 'blk-loc-sw-'.$slug, 'block' => 'locale-switcher', 'settings' => [], 'data' => []],
            ],
        ]],
    ]];

    $en = $manager->create('page', ['title' => 'About', 'slug' => $slug, 'blocks_data' => $document], $author->id);
    $en = $manager->publish($en, actorId: $author->id);

    $fr = $manager->createTranslation($en, 'fr', $author->id);
    $manager->update($fr, ['title' => 'À propos', 'blocks_data' => [[
        'id' => 'sec-loc-fr-'.$slug, 'type' => 'section', 'settings' => [],
        'columns' => [[
            'id' => 'col-loc-fr-'.$slug, 'span' => 12, 'settings' => [],
            'blocks' => [
                ['id' => 'blk-loc-fh-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Corps français']],
                ['id' => 'blk-loc-fsw-'.$slug, 'block' => 'locale-switcher', 'settings' => [], 'data' => []],
            ],
        ]],
    ]]], $author->id);
    $fr = $manager->publish($fr, actorId: $author->id);

    return ['en' => $en, 'fr' => $fr];
}

it('serves translations under their locale prefix and the fallback bare', function (): void {
    $author = localeUser();
    $pages = translatedPage($author, 'about-l10n');
    $frSlug = $pages['fr']->getAttribute('path') ?? $pages['fr']->getAttribute('slug');

    // Bare path: fallback locale.
    $this->get('/about-l10n')->assertOk()->assertSee('English body');

    // Prefixed: the French translation, with the app locale switched.
    $html = $this->get('/fr/'.$frSlug)->assertOk()->getContent();
    expect($html)->toContain('Corps français')
        ->and($html)->not->toContain('English body')
        ->and($html)->toContain('lang="fr');
});

it('renders the locale switcher linking every published translation', function (): void {
    $author = localeUser();
    $pages = translatedPage($author, 'switch-l10n');
    $frSlug = $pages['fr']->getAttribute('path') ?? $pages['fr']->getAttribute('slug');

    $html = $this->get('/switch-l10n')->assertOk()->getContent();

    expect($html)->toContain('magna-locale-switcher')
        ->and($html)->toContain('href="/fr/'.$frSlug.'"')
        // The current locale is marked, not linked.
        ->and($html)->toContain('aria-current="true"');
});

it('renders no switcher when the page has no other published translation', function (): void {
    $author = localeUser();
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Lonely', 'slug' => 'lonely-l10n',
        'blocks_data' => [[
            'id' => 'sec-lonely', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-lonely', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-lonely', 'block' => 'locale-switcher', 'settings' => [], 'data' => []]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    $this->get('/lonely-l10n')->assertOk()->assertDontSee('magna-locale-switcher');
});
