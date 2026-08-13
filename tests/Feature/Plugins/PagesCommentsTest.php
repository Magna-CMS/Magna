<?php

declare(strict_types=1);

/**
 * Node-anchored comments (§F): editorial discussion on a page or one of
 * its nodes, content-tier, plain text both ways. A comment whose node
 * vanished still reads as history.
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function commentsUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create(['name' => 'Reviewer Rana']);
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content');
    $user->assignRole($role);

    return $user;
}

it('adds, lists, and resolves comments on a page and its nodes', function (): void {
    $author = commentsUser();
    $entry = app(EntryManager::class)->create('page', [
        'title' => 'Discussed', 'slug' => 'discussed', 'blocks_data' => [],
    ], $author->id);
    $id = (string) $entry->getKey();

    $this->actingAs($author);

    // Page-level and node-anchored comments.
    $this->postJson("/pages-builder/{$id}/comments", ['body' => 'Overall looks good <script>x</script>'])
        ->assertCreated();
    $created = $this->postJson("/pages-builder/{$id}/comments", ['body' => 'Tighten this headline', 'nodeId' => 'blk-hero'])
        ->assertCreated()->json('comment.id');

    $comments = $this->getJson("/pages-builder/{$id}/comments")->assertOk()->json('comments');

    expect($comments)->toHaveCount(2)
        // Plain text both ways: markup arrives exactly as typed, as data.
        ->and($comments[0]['body'])->toBe('Overall looks good <script>x</script>')
        ->and($comments[0]['author'])->toBe('Reviewer Rana')
        ->and($comments[1]['nodeId'])->toBe('blk-hero')
        ->and($comments[1]['resolved'])->toBeFalse();

    // Resolve, and see it stick.
    $this->postJson("/pages-builder/{$id}/comments/{$created}/resolve")->assertOk();
    $comments = $this->getJson("/pages-builder/{$id}/comments")->assertOk()->json('comments');
    expect($comments[1]['resolved'])->toBeTrue();
});

it('refuses comment access without the content permission', function (): void {
    $author = commentsUser();
    $entry = app(EntryManager::class)->create('page', [
        'title' => 'Private', 'slug' => 'private-comments', 'blocks_data' => [],
    ], $author->id);

    $outsider = User::factory()->create();
    $this->actingAs($outsider)
        ->getJson('/pages-builder/'.$entry->getKey().'/comments')
        ->assertForbidden();
});
