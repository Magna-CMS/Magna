<?php

declare(strict_types=1);

/**
 * Writing a list field through the builder's patch path.
 *
 * Reported as: adding a question to a Styled FAQ answered "The Questions
 * field's item #0 is missing Question." The builder writes on every
 * keystroke, so adding a list item necessarily creates an empty row before
 * anyone can type into it — and rejecting that write refuses the click that
 * made it, with no way forward.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\DocumentEditor;
use Magna\Pages\Builder\Exceptions\PatchException;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function repeaterUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
    app(PluginManager::class)->enable('magna-cms/blog');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('pages.content');
    $user->assignRole($role);

    return $user;
}

function repeaterPage(User $author): Entry
{
    return app(EntryManager::class)->create('page', [
        'title' => 'Repeater page',
        'slug' => 'repeater-page',
        'blocks_data' => [[
            'id' => 'sec-1', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-1', 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'blk-faq', 'block' => 'blog-faq', 'settings' => [], 'data' => []],
                ],
            ]],
        ]],
    ], $author->id);
}

it('stores a blank list row so an editor can type into it', function (): void {
    $author = repeaterUser();
    $page = repeaterPage($author);

    $document = app(DocumentEditor::class)->applyPatch($page, [
        [
            'op' => 'add',
            'path' => '/0/columns/0/blocks/0/data/items',
            'value' => [['question' => '', 'answer' => '']],
        ],
    ], $author);

    // Stored, not silently dropped: the row has to come back, or the
    // inspector redraws without it and the Add button looks broken.
    expect($document[0]['columns'][0]['blocks'][0]['data']['items'])
        ->toBe([['question' => '', 'answer' => '']]);
});

it('stores a row part-way through being typed', function (): void {
    $author = repeaterUser();
    $page = repeaterPage($author);

    // The state between typing the question and typing the answer. This
    // save being refused is what made the FAQ unfillable: there is no order
    // of keystrokes that avoids it.
    $document = app(DocumentEditor::class)->applyPatch($page, [
        [
            'op' => 'add',
            'path' => '/0/columns/0/blocks/0/data/items',
            'value' => [['question' => 'Do you ship?', 'answer' => '']],
        ],
    ], $author);

    expect($document[0]['columns'][0]['blocks'][0]['data']['items'])
        ->toBe([['question' => 'Do you ship?', 'answer' => '']]);
});

it('still refuses a list that is not a list of objects', function (): void {
    $author = repeaterUser();
    $page = repeaterPage($author);

    // Structure is still enforced — that is what keeps a crafted document
    // from reaching a view.
    expect(fn () => app(DocumentEditor::class)->applyPatch($page, [
        [
            'op' => 'add',
            'path' => '/0/columns/0/blocks/0/data/items',
            'value' => ['just a string'],
        ],
    ], $author))->toThrow(PatchException::class, 'must be an object');
});
