<?php

declare(strict_types=1);

/**
 * The block schema the builder bootstraps with.
 *
 * A select may name a ProvidesOptions class instead of listing its choices,
 * because the choices are not knowable until runtime — which menus exist,
 * which content types are installed, which data sources enabled plugins
 * registered. The Livewire editor resolved those at render time; the Vue
 * builder's payload did not, so every such select arrived empty and the
 * blocks that use one could be inserted but never configured.
 */

use Magna\Auth\Role;
use Magna\Blocks\BlockRegistry;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\BuilderBootstrap;
use Magna\Pages\Menus\Menu;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function schemaUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('pages.content');
    $user->assignRole($role);

    return $user;
}

function schemaPage(User $author): Entry
{
    return app(EntryManager::class)->create('page', [
        'title' => 'Schema target',
        'slug' => 'schema-target',
        'blocks_data' => [],
    ], $author->id);
}

/**
 * @return array<string, mixed>|null
 */
function schemaField(string $blockHandle, string $fieldHandle): ?array
{
    $payload = app(BuilderBootstrap::class)->registryPayload(app(BlockRegistry::class));

    foreach ($payload as $block) {
        if ($block['handle'] !== $blockHandle) {
            continue;
        }

        foreach ($block['fields'] as $field) {
            if ($field['handle'] === $fieldHandle) {
                return $field;
            }
        }
    }

    return null;
}

it('resolves a dynamic select instead of shipping an empty list', function (): void {
    $this->actingAs(schemaUser());

    $field = schemaField('loop', 'source');

    expect($field)->not->toBeNull()
        ->and($field['type'])->toBe('select')
        // The core source is always registered, so an empty list here is
        // the bug this test exists for, not an empty install.
        ->and($field['options'])->toHaveKey('pages.latest')
        ->and($field['options']['pages.latest'])->toBe('Latest pages');
});

it('offers every block with a runtime-resolved select something to choose', function (): void {
    $this->actingAs(schemaUser());

    // The nav block's choices are whatever menus exist, so an empty list is
    // the correct answer on an install with none — give it one, or this
    // asserts nothing about the resolution it is here to cover.
    Menu::create(['handle' => 'primary', 'name' => 'Primary']);

    // Each of these names a ProvidesOptions class rather than listing its
    // choices. A block whose only required field is an empty dropdown is
    // one an editor cannot finish, so none of them may arrive empty.
    foreach ([['loop', 'source'], ['nav', 'menu'], ['entries', 'content_type']] as [$block, $handle]) {
        $field = schemaField($block, $handle);

        expect($field)->not->toBeNull("block {$block} has no {$handle} field")
            ->and($field['options'])->not->toBe([], "block {$block} field {$handle} resolved to nothing");
    }
});

it('serves the resolved options through the bootstrap endpoint', function (): void {
    $user = schemaUser();
    $page = schemaPage($user);

    $response = $this->actingAs($user)->getJson('/pages-builder/'.$page->getKey());

    $response->assertOk();

    $loop = collect($response->json('registry'))->firstWhere('handle', 'loop');
    $source = collect($loop['fields'])->firstWhere('handle', 'source');

    // Asserted through the HTTP payload as well as the builder object: the
    // builder only ever sees this, and a serialisation that dropped the
    // resolved list would pass the unit assertion above.
    expect($source['options'])->toHaveKey('pages.latest');
});
