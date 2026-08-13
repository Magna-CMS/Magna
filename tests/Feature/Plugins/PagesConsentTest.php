<?php

declare(strict_types=1);

/**
 * The consent registry (§F): registered integrations render before
 * </body> — necessary scripts live, consent categories inert until the
 * visitor's client-side choice activates them. Nothing varies the cached
 * HTML; invalid sources are dropped on read.
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Pages\PagesSettings;
use Magna\Plugins\PluginManager;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function consentUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

function consentPage(User $author, string $slug): void
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', ['title' => 'Consent host', 'slug' => $slug, 'blocks_data' => []], $author->id);
    $manager->publish($entry, actorId: $author->id);
}

/** @param list<array<string, string>> $integrations */
function registerIntegrations(array $integrations): void
{
    $settings = PagesSettings::get();
    $settings->integrations = $integrations;
    app(SettingsRepository::class)->persist($settings);
}

it('loads necessary scripts and renders consent categories inert with the banner', function (): void {
    $author = consentUser();
    registerIntegrations([
        ['handle' => 'uptime', 'label' => 'Uptime', 'category' => 'necessary', 'src' => 'https://cdn.example/uptime.js'],
        ['handle' => 'plausible', 'label' => 'Plausible', 'category' => 'analytics', 'src' => 'https://plausible.example/script.js'],
    ]);
    consentPage($author, 'consent-host');

    $html = $this->get('/consent-host')->assertOk()->getContent();

    // Necessary: a live script element.
    expect($html)->toContain('<script src="https://cdn.example/uptime.js" defer></script>')
        // Analytics: inert — type text/plain, src only as data.
        ->and($html)->toContain('type="text/plain"')
        ->and($html)->toContain('data-magna-consent="analytics"')
        ->and($html)->toContain('data-src="https://plausible.example/script.js"')
        ->and($html)->not->toContain('<script src="https://plausible.example/script.js"')
        // The banner ships with the page.
        ->and($html)->toContain('data-magna-consent-banner');
});

it('drops invalid sources and shows no banner when nothing needs consent', function (): void {
    $author = consentUser();
    registerIntegrations([
        ['handle' => 'sneaky', 'label' => 'Sneaky', 'category' => 'analytics', 'src' => 'javascript:alert(1)'],
        ['handle' => 'plain', 'label' => 'Plain', 'category' => 'necessary', 'src' => 'https://cdn.example/plain.js'],
    ]);
    consentPage($author, 'consent-clean');

    $html = $this->get('/consent-clean')->assertOk()->getContent();

    expect($html)->not->toContain('javascript:alert')
        ->and($html)->toContain('https://cdn.example/plain.js')
        // Only a necessary script left: no banner, no runtime.
        ->and($html)->not->toContain('data-magna-consent-banner');
});
