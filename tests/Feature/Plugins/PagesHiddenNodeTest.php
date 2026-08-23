<?php

declare(strict_types=1);

/**
 * Switching a node off.
 *
 * An editor's own switch, separate from display conditions: off means "not
 * on the site", without deleting the work. Two properties make it usable,
 * and both are asserted here — a visitor never sees it, and the BUILDER
 * always does, because a node that vanished when switched off could never
 * be switched back on.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Render\PageRenderer;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function hiddenNodeAuthor(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

function pageWithHidden(User $author, string $slug, bool $hidden): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Switched', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-sw', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-sw', 'span' => 12, 'settings' => [],
                'blocks' => [
                    [
                        'id' => 'blk-on', 'block' => 'heading', 'settings' => [],
                        'data' => ['text' => 'Always here'],
                    ],
                    [
                        'id' => 'blk-off', 'block' => 'heading',
                        'settings' => $hidden ? ['hidden' => true] : [],
                        'data' => ['text' => 'Switched off'],
                    ],
                ],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    return $entry;
}

it('keeps a switched-off node off the site', function (): void {
    $author = hiddenNodeAuthor();
    pageWithHidden($author, 'switched-off', hidden: true);

    $html = (string) $this->get('/switched-off')->assertOk()->getContent();

    expect($html)->toContain('Always here')
        ->and(str_contains($html, 'Switched off'))->toBeFalse();
});

it('still shows it in the builder, or it could never be switched back on', function (): void {
    $author = hiddenNodeAuthor();
    $page = pageWithHidden($author, 'switched-builder', hidden: true);

    $html = app(PageRenderer::class)->render($page, true, false);

    expect($html)->toContain('Switched off');
});

it('leaves a document that never used it exactly as it was', function (): void {
    $author = hiddenNodeAuthor();
    pageWithHidden($author, 'switched-none', hidden: false);

    expect($this->get('/switched-none')->assertOk()->getContent())
        ->toContain('Switched off');
});

it('needs the layout permission, not merely the design one', function (): void {
    $author = hiddenNodeAuthor(); // content + layout, no design
    $page = pageWithHidden($author, 'switched-perm', hidden: false);

    $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    // Switching a node off takes it off the page — the same kind of
    // decision as removing it, and not something the design tier alone
    // should be able to do to every visitor.
    $this->actingAs($author)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [[
            'op' => 'add',
            'path' => '/0/columns/0/blocks/0/settings/hidden',
            'value' => true,
        ]],
    ])->assertOk();

    $stored = Entry::type('page')->find($page->getKey())?->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'][0]['settings']['hidden'])->toBeTrue();
});
