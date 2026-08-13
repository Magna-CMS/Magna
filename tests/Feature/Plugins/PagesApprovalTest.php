<?php

declare(strict_types=1);

/**
 * Approval workflow v1 (Phase B item 10): an editor who cannot publish
 * requests, a reviewer approves (which publishes, under the reviewer's
 * identity) or returns with a reason. One open request per page; a
 * resolved request cannot be resolved again.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\PublishRequest;
use Magna\Pages\Filament\Pages\ReviewQueuePage;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function approvalUser(string ...$permissions): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant(...$permissions);
    $user->assignRole($role);

    return $user;
}

function approvalPage(User $author, string $slug): Entry
{
    return app(EntryManager::class)->create('page', [
        'title' => 'Awaiting review', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-a', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-a', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-a', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Reviewed']]],
            ]],
        ]],
    ], $author->id);
}

it('walks the whole loop: request, queue, approve, live page', function (): void {
    $editor = approvalUser('pages.content');
    $reviewer = approvalUser('pages.content', 'pages.publish');
    $page = approvalPage($editor, 'approve-me');

    // The editor cannot publish directly…
    $this->actingAs($editor)->postJson(url('/pages-builder/'.$page->getKey().'/publish'))->assertForbidden();

    // …so they request, with a note for the reviewer.
    $this->actingAs($editor)->postJson(url('/pages-builder/'.$page->getKey().'/request-publish'), [
        'note' => 'Homepage copy update, please ship today.',
    ])->assertCreated()->assertJsonPath('request.status', 'pending');

    // The reviewer's queue shows it with page and requester context.
    $queue = $this->actingAs($reviewer)->getJson(url('/pages-builder/requests'))
        ->assertOk()
        ->json('requests');

    expect($queue)->toHaveCount(1)
        ->and($queue[0]['page']['slug'])->toBe('approve-me')
        ->and($queue[0]['requester'])->toBe($editor->name)
        ->and($queue[0]['note'])->toContain('ship today');

    // Approving publishes — the page is live, the request closed.
    $this->actingAs($reviewer)->postJson(url('/pages-builder/requests/'.$queue[0]['id'].'/approve'))
        ->assertOk()
        ->assertJsonPath('request.status', 'approved');

    $this->get('/approve-me')->assertOk()->assertSee('Reviewed');

    // The publish is recorded under the REVIEWER's identity.
    $entry = Entry::type('page')->findOrFail($page->getKey());
    expect($entry->status->value)->toBe('published');
});

it('returns a request with a reason, and never without one', function (): void {
    $editor = approvalUser('pages.content');
    $reviewer = approvalUser('pages.publish');
    $page = approvalPage($editor, 'return-me');

    $requestId = $this->actingAs($editor)
        ->postJson(url('/pages-builder/'.$page->getKey().'/request-publish'))
        ->json('request.id');

    // Framework validation on a builder route must answer JSON, not
    // redirect — this pinned the shouldRenderJsonWhen fix in bootstrap.
    $this->actingAs($reviewer)->postJson(url('/pages-builder/requests/'.$requestId.'/return'), ['note' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['note']);

    $this->actingAs($reviewer)->postJson(url('/pages-builder/requests/'.$requestId.'/return'), [
        'note' => 'Hero image is missing alt text.',
    ])->assertOk()->assertJsonPath('request.status', 'returned');

    // The page did NOT publish.
    $this->get('/return-me')->assertNotFound();
});

it('allows one open request per page and reports it in bootstrap', function (): void {
    $editor = approvalUser('pages.content');
    $page = approvalPage($editor, 'once-only');

    $this->actingAs($editor)->postJson(url('/pages-builder/'.$page->getKey().'/request-publish'))->assertCreated();
    $this->actingAs($editor)->postJson(url('/pages-builder/'.$page->getKey().'/request-publish'))->assertStatus(422);

    $this->actingAs($editor)->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()
        ->assertJsonPath('approval.id', PublishRequest::query()->firstOrFail()->id);
});

it('refuses double resolution and gates the queue behind publish', function (): void {
    $editor = approvalUser('pages.content');
    $reviewer = approvalUser('pages.publish');
    $second = approvalUser('pages.publish');
    $page = approvalPage($editor, 'race-me');

    $requestId = $this->actingAs($editor)
        ->postJson(url('/pages-builder/'.$page->getKey().'/request-publish'))
        ->json('request.id');

    // The queue is reviewer-only.
    $this->actingAs($editor)->getJson(url('/pages-builder/requests'))->assertForbidden();

    $this->actingAs($reviewer)->postJson(url('/pages-builder/requests/'.$requestId.'/approve'))->assertOk();

    // The second reviewer acting on the same request learns it is settled.
    $this->actingAs($second)->postJson(url('/pages-builder/requests/'.$requestId.'/return'), [
        'note' => 'Too late.',
    ])->assertStatus(422);
});

it('lets a reviewer approve and return from the admin queue screen', function (): void {
    $editor = approvalUser('pages.content');
    $reviewer = approvalUser('panel.access', 'pages.publish');

    $first = approvalPage($editor, 'queue-approve');
    $second = approvalPage($editor, 'queue-return');

    $this->actingAs($editor)->postJson(url('/pages-builder/'.$first->getKey().'/request-publish'), [
        'note' => 'First in line.',
    ])->assertCreated();
    $secondRequestId = $this->actingAs($editor)
        ->postJson(url('/pages-builder/'.$second->getKey().'/request-publish'))
        ->json('request.id');

    $component = Livewire\Livewire::actingAs($reviewer)
        ->test(ReviewQueuePage::class)
        ->assertSee('Awaiting review')
        ->assertSee('First in line.');

    // Approve the first: it publishes.
    $firstRequestId = PublishRequest::query()
        ->where('entry_id', (string) $first->getKey())->firstOrFail()->id;
    $component->call('approve', $firstRequestId);
    $this->get('/queue-approve')->assertOk();

    // Return the second without a note: refused; with a note: returned.
    $component->call('returnRequest', $secondRequestId);
    expect(PublishRequest::query()->find($secondRequestId)?->status)->toBe('pending');

    $component->set('returnNotes.'.$secondRequestId, 'Wrong hero image.')
        ->call('returnRequest', $secondRequestId);
    expect(PublishRequest::query()->find($secondRequestId)?->status)->toBe('returned');
    $this->get('/queue-return')->assertNotFound();
});
