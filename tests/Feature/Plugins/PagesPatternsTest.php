<?php

declare(strict_types=1);

/**
 * My Library (docs/magna-pages/03-BUILDER.md §6): a pattern is validated
 * once at save, is a copy in both directions, and every instantiation gets
 * a fresh family of ids so the document's id-uniqueness rule survives the
 * same pattern being dropped in twice.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function patternUser(string ...$permissions): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant(...($permissions === [] ? ['pages.content', 'pages.layout'] : $permissions));
    $user->assignRole($role);

    return $user;
}

const PATTERN_SECTION = [
    'id' => 'sec-pat', 'type' => 'section', 'settings' => [],
    'columns' => [[
        'id' => 'col-pat', 'span' => 12, 'settings' => [],
        'blocks' => [
            ['id' => 'blk-pat-1', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Pattern heading']],
            ['id' => 'blk-pat-2', 'block' => 'text', 'settings' => [], 'data' => ['body' => '<p>Pattern body</p>']],
        ],
    ]],
];

it('saves a section pattern and lists it', function (): void {
    $user = patternUser();

    $this->actingAs($user)->postJson(url('/pages-builder/patterns'), [
        'name' => 'Hero band', 'kind' => 'section', 'node' => PATTERN_SECTION,
    ])->assertCreated()->assertJsonPath('name', 'Hero band');

    $this->actingAs($user)->getJson(url('/pages-builder/patterns'))
        ->assertOk()
        ->assertJsonPath('patterns.0.name', 'Hero band')
        ->assertJsonPath('patterns.0.kind', 'section');
});

it('refuses a structurally broken pattern at save', function (): void {
    $user = patternUser();

    $broken = PATTERN_SECTION;
    $broken['columns'][0]['span'] = 7; // spans must sum to 12

    $this->actingAs($user)->postJson(url('/pages-builder/patterns'), [
        'name' => 'Broken', 'kind' => 'section', 'node' => $broken,
    ])->assertStatus(422);
});

it('requires the layout permission to save but only content to use', function (): void {
    $contentOnly = patternUser('pages.content');

    $this->actingAs($contentOnly)->postJson(url('/pages-builder/patterns'), [
        'name' => 'Nope', 'kind' => 'section', 'node' => PATTERN_SECTION,
    ])->assertForbidden();

    $author = patternUser();
    $id = $this->actingAs($author)->postJson(url('/pages-builder/patterns'), [
        'name' => 'Usable', 'kind' => 'section', 'node' => PATTERN_SECTION,
    ])->json('id');

    $this->actingAs($contentOnly)->getJson(url('/pages-builder/patterns/'.$id.'/instance'))
        ->assertOk();
});

it('instantiates with fresh ids every time, content untouched', function (): void {
    $user = patternUser();

    $id = $this->actingAs($user)->postJson(url('/pages-builder/patterns'), [
        'name' => 'Twice', 'kind' => 'section', 'node' => PATTERN_SECTION,
    ])->json('id');

    $first = $this->actingAs($user)->getJson(url('/pages-builder/patterns/'.$id.'/instance'))->json('node');
    $second = $this->actingAs($user)->getJson(url('/pages-builder/patterns/'.$id.'/instance'))->json('node');

    // Content identical, every structural id different — from the original
    // and from each other.
    expect($first['columns'][0]['blocks'][0]['data']['text'])->toBe('Pattern heading')
        ->and($first['id'])->not->toBe('sec-pat')
        ->and($first['id'])->not->toBe($second['id'])
        ->and($first['columns'][0]['id'])->not->toBe($second['columns'][0]['id'])
        ->and($first['columns'][0]['blocks'][0]['id'])->not->toBe($second['columns'][0]['blocks'][0]['id']);
});

it('inserts an instance into a page through the normal patch wall', function (): void {
    $user = patternUser();

    $page = app(EntryManager::class)->create('page', [
        'title' => 'Canvas', 'slug' => 'pattern-canvas', 'blocks_data' => [],
    ], $user->id);

    $patternId = $this->actingAs($user)->postJson(url('/pages-builder/patterns'), [
        'name' => 'Inserted', 'kind' => 'section', 'node' => PATTERN_SECTION,
    ])->json('id');

    $instance = $this->actingAs($user)->getJson(url('/pages-builder/patterns/'.$patternId.'/instance'))->json('node');

    $this->actingAs($user)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();
    $this->actingAs($user)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [['op' => 'add', 'path' => '/-', 'value' => $instance]],
    ])->assertOk();

    $stored = Entry::type('page')->findOrFail($page->getKey())->getAttribute('blocks_data');
    expect($stored)->toHaveCount(1)
        ->and($stored[0]['columns'][0]['blocks'][0]['data']['text'])->toBe('Pattern heading');
});
