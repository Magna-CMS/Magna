<?php

declare(strict_types=1);

/**
 * ?resolve=1 on the delivery API: blocks fields come back with `_resolved`
 * attached per block (the same payload the server-side renderer consumes),
 * raw documents stay untouched without the flag, and the two variants never
 * share a cached body.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Content\ContentType;
use Magna\Content\EntryManager;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function deliveryResolveToken(): string
{
    $user = User::factory()->create();
    $result = $user->createToken('resolve-delivery', ['delivery'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $result->plainTextToken;
}

function deliveryResolveRegisterType(): void
{
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => 'resolvepage',
        'displayName' => 'Resolve Page',
        'localizable' => false,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug', 'from' => 'title'],
            ['handle' => 'blocks_data', 'type' => 'blocks'],
        ],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);
}

function deliveryResolveEntry(): string
{
    $author = User::factory()->create();
    $manager = app(EntryManager::class);

    $entry = $manager->create('resolvepage', [
        'title' => 'Resolved',
        'slug' => 'resolved',
        'blocks_data' => [[
            'id' => 'sec-resolve', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-resolve', 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'blk-text', 'block' => 'text', 'settings' => [], 'data' => ['body' => '<p>Hello <em>world</em></p>']],
                    ['id' => 'blk-heading', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Plain']],
                ],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id)->id;
}

it('attaches _resolved to blocks when resolve=1', function (): void {
    deliveryResolveRegisterType();
    $id = deliveryResolveEntry();
    $token = deliveryResolveToken();

    $response = $this->getJson("/api/v1/content/resolvepage/{$id}?resolve=1", ['Authorization' => 'Bearer '.$token])
        ->assertStatus(200);

    $blocks = $response->json('data.blocks_data.0.columns.0.blocks');
    expect($blocks)->toBeArray()->toHaveCount(2);

    // text block: resolver ran, body sanitized
    expect($blocks[0]['block'])->toBe('text')
        ->and($blocks[0]['_resolved']['body'])->toContain('<em>world</em>');

    // heading block: no resolver registered — payload passes through untouched
    expect($blocks[1]['block'])->toBe('heading')
        ->and($blocks[1])->not->toHaveKey('_resolved');
});

it('returns the raw document without the resolve flag', function (): void {
    deliveryResolveRegisterType();
    $id = deliveryResolveEntry();
    $token = deliveryResolveToken();

    $blocks = $this->getJson("/api/v1/content/resolvepage/{$id}", ['Authorization' => 'Bearer '.$token])
        ->assertStatus(200)
        ->json('data.blocks_data.0.columns.0.blocks');

    expect($blocks[0])->not->toHaveKey('_resolved');
});

it('caches resolved and raw variants under distinct keys', function (): void {
    deliveryResolveRegisterType();
    $id = deliveryResolveEntry();
    $token = deliveryResolveToken();

    // Prime the resolved variant's body cache, then request the raw variant —
    // it must not be served the resolved body.
    $this->getJson("/api/v1/content/resolvepage/{$id}?resolve=1", ['Authorization' => 'Bearer '.$token])->assertStatus(200);

    $blocks = $this->getJson("/api/v1/content/resolvepage/{$id}", ['Authorization' => 'Bearer '.$token])
        ->assertStatus(200)
        ->json('data.blocks_data.0.columns.0.blocks');

    expect($blocks[0])->not->toHaveKey('_resolved');
});

it('resolves blocks on list responses when resolve=1', function (): void {
    deliveryResolveRegisterType();
    deliveryResolveEntry();
    $token = deliveryResolveToken();

    $blocks = $this->getJson('/api/v1/content/resolvepage?resolve=1', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(200)
        ->json('data.0.blocks_data.0.columns.0.blocks');

    expect($blocks[0]['_resolved']['body'])->toContain('<em>world</em>');
});
