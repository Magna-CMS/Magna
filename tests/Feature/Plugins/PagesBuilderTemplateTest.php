<?php

declare(strict_types=1);

/**
 * Customize-header (Phase C item 1): template documents open in the SAME
 * builder as pages — same lock, same patch wall, same publish — and a part
 * edits BARE, because rendering the published header around the header
 * being edited would show two of it.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function templateBuilderUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

function headerPart(User $author): Entry
{
    return app(EntryManager::class)->create('pages_template', [
        'title' => 'Header', 'slug' => 'header', 'kind' => 'part',
        'blocks_data' => [[
            'id' => 'sec-hdr', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-hdr', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-hdr', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Site header']]],
            ]],
        ]],
    ], $author->id);
}

it('opens a template part in the builder and edits it through the patch wall', function (): void {
    $user = templateBuilderUser();
    $part = headerPart($user);

    $this->actingAs($user)->getJson(url('/pages-builder/'.$part->getKey()))
        ->assertOk()
        ->assertJsonPath('document.title', 'Header');

    $this->actingAs($user)->patchJson(url('/pages-builder/'.$part->getKey()), [
        'operations' => [['op' => 'replace', 'path' => '/0/columns/0/blocks/0/data/text', 'value' => 'New header']],
    ])->assertOk()->assertJsonPath('document.0.columns.0.blocks.0.data.text', 'New header');
});

it('renders a part canvas bare, while a page canvas still injects the published part', function (): void {
    $user = templateBuilderUser();
    $manager = app(EntryManager::class);

    $part = headerPart($user);
    $manager->publish($part, actorId: $user->id);

    $page = $manager->create('page', [
        'title' => 'With header', 'slug' => 'with-header',
        'blocks_data' => [[
            'id' => 'sec-pg', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-pg', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-pg', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Body copy']]],
            ]],
        ]],
    ], $user->id);

    // Page canvas: body content AND the injected published header.
    $pageCanvas = $this->actingAs($user)
        ->get(url('/pages-builder/'.$page->getKey().'/canvas'))
        ->assertOk()->getContent();
    expect($pageCanvas)->toContain('Body copy')->and($pageCanvas)->toContain('Site header');

    // Part canvas: exactly one header — its own document, no injection.
    $partCanvas = $this->actingAs($user)
        ->get(url('/pages-builder/'.$part->getKey().'/canvas'))
        ->assertOk()->getContent();
    expect(substr_count($partCanvas, 'Site header'))->toBe(1);
});

it('publishes a part from the builder and the live site picks it up', function (): void {
    $user = templateBuilderUser();
    $manager = app(EntryManager::class);

    $part = headerPart($user);

    $page = $manager->create('page', [
        'title' => 'Live page', 'slug' => 'live-page',
        'blocks_data' => [[
            'id' => 'sec-lv', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-lv', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-lv', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Live body']]],
            ]],
        ]],
    ], $user->id);
    $manager->publish($page, actorId: $user->id);

    // Draft part: the live page has no custom header yet.
    $this->get('/live-page')->assertOk()->assertDontSee('Site header');

    // Publish the part through the builder endpoint (lock via bootstrap).
    $this->actingAs($user)->getJson(url('/pages-builder/'.$part->getKey()))->assertOk();
    $this->actingAs($user)->postJson(url('/pages-builder/'.$part->getKey().'/publish'))
        ->assertOk()
        ->assertJsonPath('status', 'published');

    // The cache-flushed live page now carries the site-designed header.
    $this->get('/live-page')->assertOk()->assertSee('Site header');
});
