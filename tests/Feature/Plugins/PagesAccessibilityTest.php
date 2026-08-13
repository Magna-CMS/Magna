<?php

declare(strict_types=1);

/**
 * Accessibility checker v1 (Phase C item 7): advisory findings over a
 * document — missing image alt, heading-order breaks, vague link labels,
 * theme token contrast — served through the builder endpoint, gated on
 * pages.content, never blocking a save.
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Pages\Accessibility\DocumentAccessibility;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function a11ySetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/** @param list<array<string, mixed>> $blocks */
function a11yDocument(array $blocks): array
{
    return [[
        'id' => 'sec-a11y', 'type' => 'section', 'settings' => [],
        'columns' => [['id' => 'col-a11y', 'span' => 12, 'settings' => [], 'blocks' => $blocks]],
    ]];
}

it('flags missing alt text, heading skips, duplicate h1s, and vague labels', function (): void {
    a11ySetup();

    $findings = app(DocumentAccessibility::class)->check(a11yDocument([
        ['id' => 'b-img', 'block' => 'image', 'settings' => [], 'data' => ['media_id' => '01hzzzzzzzzzzzzzzzzzzzzzzz', 'alt' => '']],
        ['id' => 'b-h1a', 'block' => 'heading', 'settings' => [], 'data' => ['level' => 'h1', 'text' => 'One']],
        ['id' => 'b-h3', 'block' => 'heading', 'settings' => [], 'data' => ['level' => 'h3', 'text' => 'Skipped']],
        ['id' => 'b-h1b', 'block' => 'heading', 'settings' => [], 'data' => ['level' => 'h1', 'text' => 'Two']],
        ['id' => 'b-btn', 'block' => 'button', 'settings' => [], 'data' => ['label' => 'Click here', 'url' => '/x']],
    ]));

    $codes = array_column($findings, 'code', 'nodeId');

    expect($codes['b-img'] ?? null)->toBe('image-alt')
        ->and($codes['b-h3'] ?? null)->toBe('heading-skip')
        ->and($codes['b-h1b'] ?? null)->toBe('multiple-h1')
        ->and($codes['b-btn'] ?? null)->toBe('vague-link');
});

it('stays quiet on a clean document', function (): void {
    a11ySetup();

    $findings = app(DocumentAccessibility::class)->check(a11yDocument([
        ['id' => 'b-h2', 'block' => 'heading', 'settings' => [], 'data' => ['level' => 'h2', 'text' => 'Fine']],
        ['id' => 'b-img2', 'block' => 'image', 'settings' => [], 'data' => ['media_id' => '01hzzzzzzzzzzzzzzzzzzzzzzz', 'alt' => 'A described image']],
        ['id' => 'b-btn2', 'block' => 'button', 'settings' => [], 'data' => ['label' => 'Read the pricing guide', 'url' => '/pricing']],
    ]));

    expect($findings)->toBe([]);
});

it('serves findings through the builder endpoint, permission-gated', function (): void {
    $author = a11ySetup();
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Checked', 'slug' => 'checked',
        'blocks_data' => a11yDocument([
            ['id' => 'b-e-img', 'block' => 'image', 'settings' => [], 'data' => ['media_id' => '01hzzzzzzzzzzzzzzzzzzzzzzz', 'alt' => '']],
        ]),
    ], $author->id);

    $this->actingAs($author)
        ->getJson('/pages-builder/'.$entry->getKey().'/a11y')
        ->assertOk()
        ->assertJsonPath('findings.0.code', 'image-alt')
        ->assertJsonPath('findings.0.nodeId', 'b-e-img');

    // No pages.content, no findings.
    $outsider = User::factory()->create();
    $this->actingAs($outsider)
        ->getJson('/pages-builder/'.$entry->getKey().'/a11y')
        ->assertForbidden();
});
