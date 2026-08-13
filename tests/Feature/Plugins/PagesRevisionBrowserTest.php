<?php

declare(strict_types=1);

/**
 * The builder revision browser (Phase C item 7): list a document's
 * history, render any revision through the real themed pipeline (the
 * visual half of the diff), and restore — which requires holding the
 * document lock exactly like a patch, and is itself reversible via the
 * restore_point snapshot.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\Models\Revision;
use Magna\Pages\Builder\LockManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function revisionsUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/** @return array{0: Entry, 1: string} entry + the revision id holding the OLD content */
function revisedPage(User $author): array
{
    $manager = app(EntryManager::class);

    $doc = fn (string $text): array => [[
        'id' => 'sec-rv', 'type' => 'section', 'settings' => [],
        'columns' => [[
            'id' => 'col-rv', 'span' => 12, 'settings' => [],
            'blocks' => [['id' => 'blk-rv', 'block' => 'heading', 'settings' => [], 'data' => ['text' => $text]]],
        ]],
    ]];

    $entry = $manager->create('page', [
        'title' => 'Revised', 'slug' => 'revised', 'blocks_data' => $doc('Old headline'),
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    // Updating a published entry snapshots the OLD state first.
    $manager->update($entry, ['blocks_data' => $doc('New headline')], $author->id);

    /** @var Revision $revision */
    $revision = Revision::query()
        ->where('entry_id', $entry->getKey())
        ->where('kind', Revision::KIND_SAVE)
        ->orderByDesc('created_at')
        ->firstOrFail();

    return [$entry, $revision->id];
}

it('lists a document revision history with kinds and authors', function (): void {
    $author = revisionsUser();
    [$entry] = revisedPage($author);

    $this->actingAs($author)
        ->getJson('/pages-builder/'.$entry->getKey().'/revisions')
        ->assertOk()
        ->assertJsonPath('revisions.0.author', $author->name)
        ->assertJsonStructure(['revisions' => [['id', 'kind', 'label', 'author', 'createdAt']]]);
});

it('renders a revision preview through the themed pipeline, scoped to its document', function (): void {
    $author = revisionsUser();
    [$entry, $revisionId] = revisedPage($author);

    $html = $this->actingAs($author)
        ->get('/pages-builder/'.$entry->getKey().'/revisions/'.$revisionId.'/preview')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->getContent();

    expect($html)->toContain('Old headline')
        ->and($html)->not->toContain('New headline');

    // A revision belonging to a DIFFERENT entry is never served.
    $other = app(EntryManager::class)->create('page', [
        'title' => 'Other', 'slug' => 'other-rev', 'blocks_data' => [],
    ], $author->id);
    $this->actingAs($author)
        ->get('/pages-builder/'.$other->getKey().'/revisions/'.$revisionId.'/preview')
        ->assertNotFound();
});

it('restores only for the lock holder, reversibly', function (): void {
    $author = revisionsUser();
    [$entry, $revisionId] = revisedPage($author);
    $id = (string) $entry->getKey();

    // No lock: refused with 409, document untouched.
    $this->actingAs($author)
        ->postJson('/pages-builder/'.$id.'/revisions/'.$revisionId.'/restore')
        ->assertStatus(409);

    app(LockManager::class)->acquire($id, $author);

    $this->actingAs($author)
        ->postJson('/pages-builder/'.$id.'/revisions/'.$revisionId.'/restore')
        ->assertOk();

    // Document back to the old content...
    $stored = Entry::type('page')->findOrFail($id)->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'][0]['data']['text'])->toBe('Old headline');

    // ...and the pre-restore state was snapshotted, so this is reversible.
    expect(Revision::query()
        ->where('entry_id', $id)
        ->where('kind', Revision::KIND_RESTORE_POINT)
        ->exists())->toBeTrue();
});
