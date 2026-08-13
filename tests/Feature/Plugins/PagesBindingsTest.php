<?php

declare(strict_types=1);

/**
 * Dynamic bindings resolve at render (Phase C): a stored
 * {"$bind": "entry.title"} becomes the page's title on the public site,
 * the stored document keeps its binding, unknown sources render a gap
 * rather than an error or a leak, and a part's binding means the page it
 * appears on.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function bindingsUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.design', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/** @param array<string, mixed> $data */
function boundPage(User $author, string $slug, string $title, array $data): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => $title, 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-b-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-b-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-b-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => $data]],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('resolves entry and site bindings on the public page, keeping the stored binding', function (): void {
    $author = bindingsUser();
    $page = boundPage($author, 'bound-title', 'The Real Title', ['text' => ['$bind' => 'entry.title']]);

    $this->get('/bound-title')->assertOk()->assertSee('The Real Title');

    // The stored document still holds the binding — resolution is a view
    // concern, never a write.
    $stored = Entry::type('page')->findOrFail($page->getKey())->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'][0]['data']['text'])->toBe(['$bind' => 'entry.title']);
});

it('renders unknown or disallowed sources as a gap, never an error', function (): void {
    $author = bindingsUser();
    boundPage($author, 'bound-bad', 'Gap Page', ['text' => ['$bind' => 'entry.password']]);

    // The page renders fine; the disallowed attribute yields empty string.
    $html = $this->get('/bound-bad')->assertOk()->getContent();
    expect($html)->not->toContain('password');
});

it('resolves a template part binding against the page it appears on', function (): void {
    $author = bindingsUser();
    $manager = app(EntryManager::class);

    // A header part whose heading is bound to the current page's title.
    $part = $manager->create('pages_template', [
        'title' => 'Header', 'slug' => 'header', 'kind' => 'part',
        'blocks_data' => [[
            'id' => 'sec-ph', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-ph', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-ph', 'block' => 'heading', 'settings' => [], 'data' => ['text' => ['$bind' => 'entry.title']]]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($part, actorId: $author->id);

    boundPage($author, 'ctx-one', 'Context One', ['text' => 'Body']);
    boundPage($author, 'ctx-two', 'Context Two', ['text' => 'Body']);

    // Same part, two pages: each shows ITS OWN title in the header.
    $this->get('/ctx-one')->assertOk()->assertSee('Context One');
    $this->get('/ctx-two')->assertOk()->assertSee('Context Two');
});
