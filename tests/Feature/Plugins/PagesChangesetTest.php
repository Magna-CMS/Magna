<?php

declare(strict_types=1);

/**
 * Changesets (§A3): a named group of documents publishing together, all
 * or nothing. Drafts fold into their published rows through the normal
 * EntryManager path; one bad member aborts the whole set.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\EntryStatus;
use Magna\Pages\Changesets\Changeset;
use Magna\Pages\Changesets\ChangesetManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function changesetUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

it('publishes every member together', function (): void {
    $author = changesetUser();
    $entries = app(EntryManager::class);
    $manager = app(ChangesetManager::class);

    // Two unpublished pages and one unpublished header part.
    $home = $entries->create('page', ['title' => 'CS Home', 'slug' => 'cs-home', 'blocks_data' => []], $author->id);
    $about = $entries->create('page', ['title' => 'CS About', 'slug' => 'cs-about', 'blocks_data' => []], $author->id);
    $header = $entries->create('pages_template', ['title' => 'CS Header', 'slug' => 'cs-header', 'kind' => 'part', 'blocks_data' => []], $author->id);

    $changeset = $manager->create('Spring redesign', $author->id);
    $manager->add($changeset, 'page', (string) $home->getKey());
    $manager->add($changeset, 'page', (string) $about->getKey());
    $manager->add($changeset, 'pages_template', (string) $header->getKey());
    $manager->add($changeset, 'page', (string) $home->getKey()); // idempotent

    expect($manager->items($changeset))->toHaveCount(3);

    $manager->publish($changeset, (string) $author->getKey());

    foreach ([['page', $home], ['page', $about], ['pages_template', $header]] as [$type, $entry]) {
        expect(Entry::type($type)->whereKey($entry->getKey())->firstOrFail()->status)
            ->toBe(EntryStatus::Published);
    }
    expect($changeset->fresh()?->status)->toBe(Changeset::STATUS_PUBLISHED);

    // Re-publishing a finished changeset is refused.
    expect(fn () => $manager->publish($changeset->fresh()))->toThrow(RuntimeException::class, 'already been published');
});

it('publishes nothing when any member cannot publish', function (): void {
    $author = changesetUser();
    $entries = app(EntryManager::class);
    $manager = app(ChangesetManager::class);

    $keeper = $entries->create('page', ['title' => 'Keeper', 'slug' => 'cs-keeper', 'blocks_data' => []], $author->id);
    $goner = $entries->create('page', ['title' => 'Goner', 'slug' => 'cs-goner', 'blocks_data' => []], $author->id);

    $changeset = $manager->create('Doomed', $author->id);
    $manager->add($changeset, 'page', (string) $keeper->getKey());
    $manager->add($changeset, 'page', (string) $goner->getKey());

    // A member vanishes between add and publish.
    $entries->delete(Entry::type('page')->whereKey($goner->getKey())->firstOrFail());

    expect(fn () => $manager->publish($changeset))->toThrow(RuntimeException::class, 'nothing in the changeset');

    // Atomicity held: the surviving member did NOT publish.
    expect(Entry::type('page')->whereKey($keeper->getKey())->firstOrFail()->status)
        ->not->toBe(EntryStatus::Published)
        ->and($changeset->fresh()?->status)->toBe(Changeset::STATUS_OPEN);
});

it('refuses foreign types, unknown documents, and empty publishes', function (): void {
    $author = changesetUser();
    $manager = app(ChangesetManager::class);
    $changeset = $manager->create('Strict', $author->id);

    expect(fn () => $manager->add($changeset, 'user', 'whatever'))
        ->toThrow(InvalidArgumentException::class, 'cannot contain')
        ->and(fn () => $manager->add($changeset, 'page', '01hzzzzzzzzzzzzzzzzzzzzzzz'))
        ->toThrow(InvalidArgumentException::class, 'No such document')
        ->and(fn () => $manager->publish($changeset))
        ->toThrow(RuntimeException::class, 'empty changeset');
});
