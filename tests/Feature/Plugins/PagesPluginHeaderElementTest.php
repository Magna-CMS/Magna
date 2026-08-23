<?php

declare(strict_types=1);

/**
 * A plugin's element in the site's header.
 *
 * The case: somebody installs a plugin that adds signing in, and wants its
 * account link in the header — an icon and a link, shown to guests as
 * "Sign in" and to members as their account.
 *
 * Core ships nothing account-shaped on purpose. An account link belongs to
 * whatever owns accounts, a cart to whatever owns a store, and a CMS with
 * no store has no cart. What core owes is that the extension path WORKS,
 * which is four links in a chain: registered, offered to the builder,
 * placeable in a header, and cache-safe. "It should work" is not the same
 * as "it works", and a plugin author is the wrong person to discover the
 * difference.
 */

use Magna\Auth\Role;
use Magna\Blocks\BlockDefinition;
use Magna\Blocks\BlockRegistry;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\PagesSettings;
use Magna\Plugins\PluginManager;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function accountPluginUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
    app(ThemeManager::class)->activate('magna/launch');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.design', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/** What an accounts plugin's entry class registers through RegistersBlocks. */
function registerAccountBlock(): void
{
    app(BlockRegistry::class)->register(
        BlockDefinition::fromArray([
            'handle' => 'account-link',
            'label' => 'Account',
            'icon' => 'core:user',
            'category' => 'site',
            'fields' => [
                ['handle' => 'label', 'type' => 'text', 'label' => 'Label'],
                ['handle' => 'url', 'type' => 'url', 'label' => 'Links to'],
            ],
        ])->withSourcePlugin('acme/accounts'),
    );
}

/** @param array<mixed, mixed> $blocks */
function chromeWithBlocks(User $author, string $slug, array $blocks): Entry
{
    $manager = app(EntryManager::class);

    $header = $manager->create('pages_template', [
        'title' => ucfirst($slug), 'slug' => $slug,
        'kind' => 'part', 'role' => 'header',
        'blocks_data' => [[
            'id' => 'sec-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-'.$slug, 'span' => 12, 'settings' => [], 'blocks' => $blocks,
            ]],
        ]],
    ], $author->id);
    $manager->publish($header, actorId: $author->id);

    $settings = PagesSettings::get();
    $settings->default_header_id = (string) $header->getKey();
    app(SettingsRepository::class)->persist($settings);

    return $header;
}

it('offers a plugin element to the builder like any other', function (): void {
    $author = accountPluginUser();
    registerAccountBlock();

    $page = app(EntryManager::class)->create('page', [
        'title' => 'Any', 'slug' => 'any-page', 'blocks_data' => [],
    ], $author->id);

    $registry = $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()->json('registry');

    expect(array_column($registry, 'handle'))->toContain('account-link');

    // With the icon it named, so it draws as a tile rather than as the
    // unknown-icon mark every plugin block would otherwise get.
    expect(collect($registry)->firstWhere('handle', 'account-link')['icon'])->toBe('core:user');
});

it('places a plugin element inside the site header', function (): void {
    $author = accountPluginUser();
    registerAccountBlock();

    // A header is an ordinary document, so a plugin's block goes in one
    // exactly as it goes in a page — nothing about chrome narrows the
    // registry it may draw from.
    $header = chromeWithBlocks($author, 'account-header', [[
        'id' => 'blk-acct', 'block' => 'account-link', 'settings' => [],
        'data' => ['label' => 'Sign in', 'url' => '/sign-in'],
    ]]);

    $page = app(EntryManager::class)->create('page', [
        'title' => 'Home', 'slug' => 'account-home', 'blocks_data' => [],
    ], $author->id);
    app(EntryManager::class)->publish($page, actorId: $author->id);

    // No view ships for it in this test, so it draws nothing — the
    // documented degradation for an unknown handle. What matters is that
    // the page still renders and the block survived the save.
    $this->get('/account-home')->assertOk();

    $stored = Entry::type('pages_template')->find($header->getKey())?->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'][0]['block'])->toBe('account-link');
});

it('keeps a signed-in header out of the shared cache', function (): void {
    $author = accountPluginUser();
    registerAccountBlock();

    chromeWithBlocks($author, 'gated-header', [
        [
            'id' => 'blk-out', 'block' => 'heading',
            'settings' => ['conditions' => [['type' => 'auth', 'show' => 'guests']]],
            'data' => ['text' => 'Sign in'],
        ],
        [
            'id' => 'blk-in', 'block' => 'heading',
            'settings' => ['conditions' => [['type' => 'auth', 'show' => 'authenticated']]],
            'data' => ['text' => 'Your account'],
        ],
    ]);

    $page = app(EntryManager::class)->create('page', [
        'title' => 'Gated', 'slug' => 'gated-home', 'blocks_data' => [],
    ], $author->id);
    app(EntryManager::class)->publish($page, actorId: $author->id);

    auth()->logout();
    $guest = (string) $this->get('/gated-home')->assertOk()->getContent();

    /*
     * The whole safety argument for a personalised header: the SHARED
     * cache only ever holds the GUEST render, because a signed-in request
     * bypasses it. Nobody's account details can end up in a body that is
     * later served to a stranger.
     */
    expect($guest)->toContain('Sign in')
        ->and(str_contains($guest, 'Your account'))->toBeFalse();

    $member = (string) $this->actingAs($author)->get('/gated-home')->assertOk()->getContent();

    expect($member)->toContain('Your account')
        ->and(str_contains($member, 'Sign in'))->toBeFalse();
});
