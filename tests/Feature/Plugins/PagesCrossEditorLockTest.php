<?php

declare(strict_types=1);

/**
 * Two editors, one lock (§E2): the Livewire structured editor and the
 * visual builder contend for the SAME document lock, in both directions.
 * Without this, the fallback editor is a silent path around everything the
 * lock guarantees.
 */

use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Blocks\Livewire\BlockEditor;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\LockManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function crossLockUser(): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout');
    $user->assignRole($role);

    return $user;
}

function crossLockPage(User $author): Entry
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    return app(EntryManager::class)->create('page', [
        'title' => 'Cross page',
        'slug' => 'cross-page',
        'blocks_data' => [[
            'id' => 'sec-x', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-x', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-x', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Cross']]],
            ]],
        ]],
    ], $author->id);
}

it('shows the structured editor who holds the lock and refuses its save', function (): void {
    $builderUser = crossLockUser();
    $livewireUser = crossLockUser();
    $page = crossLockPage($builderUser);

    // The builder takes the lock first.
    $this->actingAs($builderUser)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    // The structured editor opens read-only with the holder named…
    $component = Livewire::actingAs($livewireUser)->test(BlockEditor::class, [
        'blocksData' => json_encode($page->getAttribute('blocks_data')),
        'entryId' => (string) $page->getKey(),
    ]);

    $component->assertSet('lockedBy', $builderUser->name);

    // …and save() refuses to serialise, so the modelable never updates.
    $before = $component->get('blocksData');
    $component->call('save');

    expect($component->get('blocksData'))->toBe($before)
        ->and($component->get('saveStatus'))->toContain('Not saved');
});

it('blocks the builder while the structured editor holds the lock', function (): void {
    $livewireUser = crossLockUser();
    $builderUser = crossLockUser();
    $page = crossLockPage($livewireUser);

    // Opening the structured editor acquires the shared lock.
    Livewire::actingAs($livewireUser)->test(BlockEditor::class, [
        'blocksData' => json_encode($page->getAttribute('blocks_data')),
        'entryId' => (string) $page->getKey(),
    ])->assertSet('lockedBy', null);

    expect(app(LockManager::class)->holds((string) $page->getKey(), $livewireUser))->toBeTrue();

    // The builder now sees the holder and its writes are refused.
    $this->actingAs($builderUser)->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()
        ->assertJsonPath('lock.mine', false)
        ->assertJsonPath('lock.holder.name', $livewireUser->name);

    $this->actingAs($builderUser)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [['op' => 'replace', 'path' => '/0/columns/0/blocks/0/data/text', 'value' => 'Stolen']],
    ])->assertStatus(409);
});

it('leaves the structured editor unguarded when the pages plugin is absent', function (): void {
    // No plugin, no binding — the editor works exactly as before Pages
    // existed. Guard only that mount and save survive without a provider.
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(BlockEditor::class, [
        'blocksData' => '[]',
        'entryId' => '01hxnosuchentry00000000000',
    ]);

    $component->assertSet('lockedBy', null);
    $component->call('save');

    expect($component->get('saveStatus'))->toContain('Saved');
});
