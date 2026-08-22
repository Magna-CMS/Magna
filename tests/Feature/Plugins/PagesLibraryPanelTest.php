<?php

declare(strict_types=1);

/**
 * The builder's cloud-library window (§C10 + 06-CLOUD-LIBRARY): a proxy to
 * the hub through one hardened egress client. Missing blocks are computed
 * on THIS site against its registry; instances arrive with fresh ids and
 * insert through the ordinary patch wall; a dead hub degrades to an empty
 * panel, never a broken builder.
 */

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Marketplace\Marketplace;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function libraryPanelUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
    Cache::flush(); // browse cache must not leak between tests

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('pages.content', 'pages.layout');
    $user->assignRole($role);

    return $user;
}

function fakeHubCatalog(): void
{
    Http::fake([
        Marketplace::API_BASE.'/library/collections' => Http::response([
            'collections' => [[
                'slug' => 'starter', 'name' => 'Starter', 'description' => null,
                'publisher' => 'Magna', 'assetCount' => 1,
            ]],
        ]),
        Marketplace::API_BASE.'/library/hub-hero' => Http::response([
            'slug' => 'hub-hero', 'name' => 'Hub hero', 'kind' => 'pattern', 'version' => 1,
            'requiredBlocks' => ['hero'],
            'document' => [
                'id' => 'sec-hub', 'type' => 'section', 'settings' => [],
                'columns' => [[
                    'id' => 'col-hub', 'span' => 12, 'settings' => [],
                    'blocks' => [['id' => 'blk-hub', 'block' => 'hero', 'settings' => [], 'data' => ['headline' => 'From the hub']]],
                ]],
            ],
        ]),
        Marketplace::API_BASE.'/library*' => Http::response([
            'assets' => [
                ['slug' => 'hub-hero', 'name' => 'Hub hero', 'kind' => 'pattern',
                    'description' => null, 'requiredBlocks' => ['hero'], 'isFree' => true, 'downloads' => 9, 'version' => 1],
                ['slug' => 'exotic', 'name' => 'Exotic widget', 'kind' => 'pattern',
                    'description' => null, 'requiredBlocks' => ['vendor-carousel'], 'isFree' => true, 'downloads' => 2, 'version' => 1],
            ],
        ]),
    ]);
}

it('browses the hub with missing blocks computed against this site', function (): void {
    $user = libraryPanelUser();
    fakeHubCatalog();

    $payload = $this->actingAs($user)->getJson(url('/pages-builder/library'))->assertOk()->json();

    expect($payload['assets'])->toHaveCount(2)
        ->and($payload['assets'][0]['missingBlocks'])->toBe([])                 // hero is installed
        ->and($payload['assets'][1]['missingBlocks'])->toBe(['vendor-carousel']) // this is not
        ->and($payload['collections'][0]['slug'])->toBe('starter');
});

it('instantiates a hub asset with fresh ids and inserts it through the patch wall', function (): void {
    $user = libraryPanelUser();
    fakeHubCatalog();

    $page = app(EntryManager::class)->create('page', [
        'title' => 'Hub target', 'slug' => 'hub-target', 'blocks_data' => [],
    ], $user->id);

    $instance = $this->actingAs($user)->getJson(url('/pages-builder/library/hub-hero/instance'))
        ->assertOk()
        ->json();

    expect($instance['node']['id'])->not->toBe('sec-hub')
        ->and($instance['node']['columns'][0]['blocks'][0]['data']['headline'])->toBe('From the hub');

    $this->actingAs($user)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();
    $this->actingAs($user)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [['op' => 'add', 'path' => '/-', 'value' => $instance['node']]],
    ])->assertOk();

    $stored = Entry::type('page')->findOrFail($page->getKey())->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'][0]['data']['headline'])->toBe('From the hub');
});

it('gives a block-kind asset fresh ids so the same block can be dropped twice', function (): void {
    $user = libraryPanelUser();
    Http::fake([
        Marketplace::API_BASE.'/library/hub-card' => Http::response([
            'slug' => 'hub-card', 'name' => 'Hub card', 'kind' => 'block', 'version' => 1,
            'requiredBlocks' => ['hero'],
            // A block-kind asset IS one block node — the shape a column takes.
            'document' => ['id' => 'blk-card', 'block' => 'hero', 'settings' => [], 'data' => ['headline' => 'Card']],
        ]),
    ]);

    $first = $this->actingAs($user)->getJson(url('/pages-builder/library/hub-card/instance'))->assertOk()->json();
    $second = $this->actingAs($user)->getJson(url('/pages-builder/library/hub-card/instance'))->assertOk()->json();

    expect($first['kind'])->toBe('block')
        ->and($first['node']['block'])->toBe('hero')
        ->and($first['node']['id'])->not->toBe('blk-card')
        ->and($second['node']['id'])->not->toBe($first['node']['id']);
});

it('gives every section of a list-shaped asset fresh ids', function (): void {
    $user = libraryPanelUser();
    Http::fake([
        Marketplace::API_BASE.'/library/hub-site' => Http::response([
            'slug' => 'hub-site', 'name' => 'Hub site', 'kind' => 'page', 'version' => 1,
            'requiredBlocks' => ['hero'],
            // A page or part ships a LIST of sections, not a single node.
            'document' => [
                ['id' => 'sec-a', 'type' => 'section', 'settings' => [], 'columns' => [[
                    'id' => 'col-a', 'span' => 12, 'settings' => [],
                    'blocks' => [['id' => 'blk-a', 'block' => 'hero', 'settings' => [], 'data' => []]],
                ]]],
                ['id' => 'sec-b', 'type' => 'section', 'settings' => [], 'columns' => []],
            ],
        ]),
    ]);

    $node = $this->actingAs($user)->getJson(url('/pages-builder/library/hub-site/instance'))
        ->assertOk()->json('node');

    // Importing the same asset twice must not put two sections with one id
    // on the page, so the list has to be walked like any other subtree.
    expect($node[0]['id'])->not->toBe('sec-a')
        ->and($node[1]['id'])->not->toBe('sec-b')
        ->and($node[0]['columns'][0]['id'])->not->toBe('col-a')
        ->and($node[0]['columns'][0]['blocks'][0]['id'])->not->toBe('blk-a');
});

it('previews an asset through the local renderer', function (): void {
    $user = libraryPanelUser();
    fakeHubCatalog();

    // A live local render, not a hub screenshot: the preview shows the
    // asset on THIS theme. Bare of parts, because the asset is the point.
    $preview = $this->actingAs($user)->get(url('/pages-builder/library/hub-hero/preview'))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->getContent();

    expect($preview)->toContain('From the hub')
        ->and($preview)->toContain('magna-section');
});

it('refuses to preview what the actor could not insert', function (): void {
    $user = libraryPanelUser();
    Http::fake([
        Marketplace::API_BASE.'/library/hub-html' => Http::response([
            'slug' => 'hub-html', 'name' => 'Raw HTML', 'kind' => 'pattern', 'version' => 1,
            'requiredBlocks' => ['html'],
            // The html block requires a permission this actor lacks, and a
            // preview is a render: the same authorization wall applies
            // BEFORE any hub markup reaches a browser.
            'document' => [
                'id' => 'sec-html', 'type' => 'section', 'settings' => [],
                'columns' => [[
                    'id' => 'col-html', 'span' => 12, 'settings' => [],
                    'blocks' => [['id' => 'blk-html', 'block' => 'html', 'settings' => [], 'data' => ['markup' => '<script>alert(1)</script>']]],
                ]],
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->get(url('/pages-builder/library/hub-html/preview'))
        ->assertStatus(422);

    expect($response->getContent())->not->toContain('<script>alert(1)</script>');
});

it('previews a block-kind asset by scaffolding it into a section', function (): void {
    $user = libraryPanelUser();
    Http::fake([
        Marketplace::API_BASE.'/library/hub-card' => Http::response([
            'slug' => 'hub-card', 'name' => 'Hub card', 'kind' => 'block', 'version' => 1,
            'requiredBlocks' => ['heading'],
            'document' => ['id' => 'blk-card', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Card preview', 'level' => 'h2']],
        ]),
    ]);

    $preview = $this->actingAs($user)->get(url('/pages-builder/library/hub-card/preview'))
        ->assertOk()
        ->getContent();

    expect($preview)->toContain('Card preview');
});

it('keeps a paid asset unseen without a licence, in preview as in instance', function (): void {
    $user = libraryPanelUser();
    Http::fake([
        Marketplace::API_BASE.'/library/hub-paid' => Http::response([
            'slug' => 'hub-paid', 'name' => 'Paid thing', 'kind' => 'pattern', 'version' => 1,
            'requiredBlocks' => [], 'licenseRequired' => true, 'productSlug' => 'pro-kit',
            'document' => ['id' => 'sec-paid', 'type' => 'section', 'settings' => [], 'columns' => []],
        ]),
    ]);

    $this->actingAs($user)->get(url('/pages-builder/library/hub-paid/preview'))->assertStatus(402);
});

it('degrades to an empty library when the hub is unreachable', function (): void {
    $user = libraryPanelUser();
    Http::fake(fn () => throw new ConnectionException('refused'));

    $this->actingAs($user)->getJson(url('/pages-builder/library'))
        ->assertOk()
        ->assertJsonPath('assets', [])
        ->assertJsonPath('collections', []);
});
