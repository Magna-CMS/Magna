<?php

declare(strict_types=1);

/**
 * Performance meter v1 (Phase C item 7): the page weighed through the real
 * render pipeline — sizes from what actually ships, counts from the
 * document, advisory notes on budget breaches. Served through the builder
 * endpoint, gated on pages.content.
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function perfSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

it('measures a page and reports metrics through the builder endpoint', function (): void {
    $author = perfSetup();
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Weighed', 'slug' => 'weighed',
        'blocks_data' => [[
            'id' => 'sec-perf', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-perf', 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'b-perf-h', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Hello']],
                    ['id' => 'b-perf-d', 'block' => 'divider', 'settings' => [], 'data' => []],
                ],
            ]],
        ]],
    ], $author->id);

    $body = $this->actingAs($author)
        ->getJson('/pages-builder/'.$entry->getKey().'/performance')
        ->assertOk()
        ->json();

    expect($body['metrics']['blockCount'])->toBe(2)
        ->and($body['metrics']['sectionCount'])->toBe(1)
        ->and($body['metrics']['htmlBytes'])->toBeGreaterThan(0)
        ->and($body['metrics']['gzippedBytes'])->toBeGreaterThan(0)
        ->and($body['metrics']['gzippedBytes'])->toBeLessThan($body['metrics']['htmlBytes'])
        // A two-block page breaches no budget.
        ->and($body['notes'])->toBe([]);

    // No pages.content, no numbers.
    $outsider = User::factory()->create();
    $this->actingAs($outsider)
        ->getJson('/pages-builder/'.$entry->getKey().'/performance')
        ->assertForbidden();
});
