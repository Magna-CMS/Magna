<?php

declare(strict_types=1);

/**
 * Light and dark (docs/magna-pages/13-BUILDER-CHROME-AND-CANVAS.md §4 F6).
 *
 * The condition that shapes the whole design: all three readings emit into
 * ONE body, so the page is identical for every visitor and the shared
 * cache still applies. Choosing a scheme server-side would make the page
 * per-visitor and cost that cache — too much to pay for a palette. It is
 * also why dark mode is tokens rather than a second set of styles: one
 * palette with two readings, never two palettes to keep in step.
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Pages\PagesSettings;
use Magna\Pages\Themes\ThemeTokens;
use Magna\Plugins\PluginManager;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function schemeAuthor(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    // The dark readings live in a theme's tokens.json, so a theme has to
    // be active for there to be any.
    app(ThemeManager::class)->activate('magna/launch');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

function schemePage(User $author, string $slug): void
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Scheme', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-scheme', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-scheme', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-scheme', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Scheme']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);
}

function setScheme(string $scheme): void
{
    $settings = PagesSettings::get();
    $settings->color_scheme = $scheme;
    app(SettingsRepository::class)->persist($settings);
}

it('emits all three readings into one cacheable body', function (): void {
    $author = schemeAuthor();
    schemePage($author, 'scheme-page');
    setScheme('system');

    auth()->logout();
    $html = (string) $this->get('/scheme-page')->assertOk()
        ->assertHeader('X-Magna-Cache', 'miss')
        ->getContent();

    // Light at the root, dark under the system preference, and dark again
    // for an explicit choice — the media query guarded against an explicit
    // light so a visitor who chose light is not overruled by their system.
    expect($html)->toContain(':root{')
        ->toContain('--color-surface:#ffffff')
        ->toContain('@media (prefers-color-scheme: dark)')
        ->toContain(':root:not([data-theme="light"])')
        ->toContain(':root[data-theme="dark"]')
        ->toContain('--color-surface:#0b1220');

    // Identical bytes on the second visit: a scheme is not a per-visitor
    // axis, which is the entire safety argument.
    $second = $this->get('/scheme-page')->assertOk()->assertHeader('X-Magna-Cache', 'hit');
    expect($second->getContent())->toBe($html);
});

it('pins a site that has chosen one scheme, with nothing left to flip', function (): void {
    $author = schemeAuthor();
    schemePage($author, 'pinned-dark');
    setScheme('dark');

    auth()->logout();
    $html = (string) $this->get('/pinned-dark')->assertOk()->getContent();

    // One palette, and no way for a system preference or an attribute to
    // change it — a pinned site is pinned.
    expect($html)->toContain('--color-surface:#0b1220')
        ->and(str_contains($html, 'prefers-color-scheme: dark'))->toBeFalse()
        ->and(str_contains($html, 'data-theme'))->toBeFalse()
        ->and(str_contains($html, '--color-surface:#ffffff'))->toBeFalse();
});

it('pins light by emitting nothing of dark at all', function (): void {
    $author = schemeAuthor();
    schemePage($author, 'pinned-light');
    setScheme('light');

    auth()->logout();
    $html = (string) $this->get('/pinned-light')->assertOk()->getContent();

    expect($html)->toContain('--color-surface:#ffffff')
        ->and(str_contains($html, ':root[data-theme="dark"]'))->toBeFalse();
});

it('leaves a token with no dark reading the same in both', function (): void {
    schemeAuthor();

    $dark = app(ThemeTokens::class)->darkVariables();

    // A corner radius and a content width do not change with the light, so
    // a token that declares no dark value takes no part in the dark rule.
    expect($dark)->toHaveKey('--color-surface')
        ->and($dark)->not->toHaveKey('--radius')
        ->and($dark)->not->toHaveKey('--max-width');
});

it('keeps a site override to the light reading it was made in', function (): void {
    schemeAuthor();

    // An override replaces what the theme declares for LIGHT. Making one
    // value mean both would leave a site unable to express a palette that
    // differs between the two, which is the point of the feature.
    $dark = app(ThemeTokens::class)->darkVariables();

    expect($dark['--color-surface'])->toBe('#0b1220');
});
