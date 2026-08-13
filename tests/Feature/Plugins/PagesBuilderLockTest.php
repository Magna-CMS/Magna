<?php

declare(strict_types=1);

/**
 * Document lock with takeover (docs/magna-pages/03-BUILDER.md §2).
 *
 * The invariant: at most one editor's writes land at a time, the other
 * editor can SEE who holds the document, and a dead tab releases by going
 * quiet rather than holding the page hostage.
 */

use Illuminate\Support\Carbon;
use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\LockManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function lockSetup(): void
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
}

function lockUser(): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('pages.content', 'pages.layout');
    $user->assignRole($role);

    return $user;
}

function lockPage(User $author, string $slug): Entry
{
    return app(EntryManager::class)->create('page', [
        'title' => 'Locked page',
        'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-l', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-l', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-l', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Locked']]],
            ]],
        ]],
    ], $author->id);
}

const LOCK_EDIT = [
    'operations' => [['op' => 'replace', 'path' => '/0/columns/0/blocks/0/data/text', 'value' => 'Changed']],
];

it('gives the first opener the lock and tells the second who holds it', function (): void {
    lockSetup();
    $first = lockUser();
    $second = lockUser();
    $page = lockPage($first, 'lock-first');

    $this->actingAs($first)->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()
        ->assertJsonPath('lock.mine', true);

    $this->actingAs($second)->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()
        ->assertJsonPath('lock.mine', false)
        ->assertJsonPath('lock.holder.name', $first->name);
});

it('refuses writes from an editor who does not hold the lock', function (): void {
    lockSetup();
    $holder = lockUser();
    $other = lockUser();
    $page = lockPage($holder, 'lock-writes');

    $this->actingAs($holder)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    $this->actingAs($other)->patchJson(url('/pages-builder/'.$page->getKey()), LOCK_EDIT)
        ->assertStatus(409)
        ->assertJsonPath('lock.holder.name', $holder->name);

    // The document is untouched.
    $stored = $page->fresh()?->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'][0]['data']['text'])->toBe('Locked');
});

it('lets a colleague take over, after which the old holder is refused', function (): void {
    lockSetup();
    $first = lockUser();
    $second = lockUser();
    $page = lockPage($first, 'lock-takeover');

    $this->actingAs($first)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    $this->actingAs($second)->postJson(url('/pages-builder/'.$page->getKey().'/take-over'))
        ->assertOk()
        ->assertJsonPath('lock.mine', true);

    // The new holder writes; the old holder learns on their next write.
    $this->actingAs($second)->patchJson(url('/pages-builder/'.$page->getKey()), LOCK_EDIT)->assertOk();
    $this->actingAs($first)->patchJson(url('/pages-builder/'.$page->getKey()), LOCK_EDIT)->assertStatus(409);
});

it('treats a quiet lock as released', function (): void {
    lockSetup();
    $first = lockUser();
    $second = lockUser();
    $page = lockPage($first, 'lock-stale');

    $this->actingAs($first)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    // No heartbeat past the TTL: the crashed tab never says goodbye.
    Carbon::setTestNow(now()->addSeconds(LockManager::TTL_SECONDS + 5));

    $this->actingAs($second)->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()
        ->assertJsonPath('lock.mine', true);

    Carbon::setTestNow();
});

it('keeps a lock alive through heartbeats and refuses a non-holder heartbeat', function (): void {
    lockSetup();
    $holder = lockUser();
    $other = lockUser();
    $page = lockPage($holder, 'lock-heartbeat');

    $this->actingAs($holder)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    Carbon::setTestNow(now()->addSeconds(LockManager::TTL_SECONDS - 10));
    $this->actingAs($holder)->postJson(url('/pages-builder/'.$page->getKey().'/heartbeat'))
        ->assertOk()
        ->assertJsonPath('held', true);

    // The heartbeat pushed expiry forward; past the ORIGINAL ttl the lock
    // still stands.
    Carbon::setTestNow(now()->addSeconds(20));
    expect(app(LockManager::class)->holds((string) $page->getKey(), $holder))->toBeTrue();

    $this->actingAs($other)->postJson(url('/pages-builder/'.$page->getKey().'/heartbeat'))
        ->assertStatus(409);

    Carbon::setTestNow();
});

it('releases cleanly on close so the next opener starts fresh', function (): void {
    lockSetup();
    $first = lockUser();
    $second = lockUser();
    $page = lockPage($first, 'lock-release');

    $this->actingAs($first)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();
    $this->actingAs($first)->postJson(url('/pages-builder/'.$page->getKey().'/release'))->assertOk();

    $this->actingAs($second)->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()
        ->assertJsonPath('lock.mine', true);
});

it('re-opening in a second tab keeps the same user editing', function (): void {
    lockSetup();
    $user = lockUser();
    $page = lockPage($user, 'lock-same-user');

    $this->actingAs($user)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();
    $this->actingAs($user)->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()
        ->assertJsonPath('lock.mine', true);

    $this->actingAs($user)->patchJson(url('/pages-builder/'.$page->getKey()), LOCK_EDIT)->assertOk();
});

it('publishes from the builder behind its own permission and the lock', function (): void {
    lockSetup();

    // Content-only editor: may edit, may not ship.
    $editor = lockUser();
    $page = lockPage($editor, 'publish-denied');
    $this->actingAs($editor)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();
    $this->actingAs($editor)->postJson(url('/pages-builder/'.$page->getKey().'/publish'))->assertForbidden();

    // Publisher without the lock: refused with the holder named.
    $publisher = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('pages.content', 'pages.publish');
    $publisher->assignRole($role);

    $this->actingAs($publisher)->postJson(url('/pages-builder/'.$page->getKey().'/publish'))
        ->assertStatus(409);

    // Publisher who takes over: publishes, page goes live at its path.
    $this->actingAs($publisher)->postJson(url('/pages-builder/'.$page->getKey().'/take-over'))->assertOk();
    $this->actingAs($publisher)->postJson(url('/pages-builder/'.$page->getKey().'/publish'))
        ->assertOk()
        ->assertJsonPath('status', 'published');

    $this->get('/publish-denied')->assertOk()->assertSee('Locked');
});
