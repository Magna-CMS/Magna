<?php

declare(strict_types=1);

/**
 * Editing a header shows ONE header, and it is the one being edited.
 *
 * Reported as "2 header is showing, the updated things are not updating as
 * header". Both halves came from the same choice: chrome was rendered
 * bare, so the theme drew its own fallback header AND the part's sections
 * landed in the main slot. The editor saw two headers, neither of them
 * theirs, and nothing they typed appeared in a header at all.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function chromeSlotAuthor(): User
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

function chromePartFor(User $author, string $role, string $text): Entry
{
    return app(EntryManager::class)->create('pages_template', [
        'title' => ucfirst($role), 'slug' => 'slot-'.$role,
        'kind' => 'part', 'role' => $role,
        'blocks_data' => [[
            'id' => 'sec-'.$role, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-'.$role, 'span' => 12, 'settings' => [],
                'blocks' => [[
                    'id' => 'blk-'.$role, 'block' => 'heading', 'settings' => [],
                    'data' => ['text' => $text],
                ]],
            ]],
        ]],
    ], $author->id);
}

it('draws the header being edited in the header, and only once', function (): void {
    $author = chromeSlotAuthor();
    $header = chromePartFor($author, 'header', 'My own header');

    $html = (string) $this->actingAs($author)
        ->get(url('/pages-builder/'.$header->getKey().'/canvas'))
        ->assertOk()
        ->getContent();

    // One header element, and it is the custom slot rather than the
    // theme's fallback — which is what "two headers" was.
    expect(substr_count($html, '<header'))->toBe(1)
        ->and($html)->toContain('l-header--custom');

    // The thing being edited is IN it, so an edit changes what the editor
    // is looking at.
    $beforeMain = substr($html, 0, strpos($html, '<main') ?: strlen($html));
    expect($beforeMain)->toContain('My own header');
});

it('draws a footer being edited in the footer', function (): void {
    $author = chromeSlotAuthor();
    $footer = chromePartFor($author, 'footer', 'My own footer');

    $html = (string) $this->actingAs($author)
        ->get(url('/pages-builder/'.$footer->getKey().'/canvas'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('l-footer--custom')
        ->and($html)->toContain('My own footer');
});

it('marks the edited chrome as this session’s own document', function (): void {
    $author = chromeSlotAuthor();
    $header = chromePartFor($author, 'header', 'Mine');

    $html = (string) $this->actingAs($author)
        ->get(url('/pages-builder/'.$header->getKey().'/canvas'))
        ->assertOk()
        ->getContent();

    // Nodes are selectable, and carry NO document marker: the marker means
    // "another document", and this one is the document being edited.
    expect($html)->toContain('data-magna-node="blk-header"')
        ->and(str_contains($html, 'data-magna-doc'))->toBeFalse();
});

it('still edits a part with no chrome role bare', function (): void {
    $author = chromeSlotAuthor();

    // A popup has no slot to sit in, so it keeps rendering as content.
    $popup = app(EntryManager::class)->create('pages_template', [
        'title' => 'Sale', 'slug' => 'sale-popup', 'kind' => 'popup',
        'blocks_data' => [],
    ], $author->id);

    $this->actingAs($author)
        ->get(url('/pages-builder/'.$popup->getKey().'/canvas'))
        ->assertOk();
});
