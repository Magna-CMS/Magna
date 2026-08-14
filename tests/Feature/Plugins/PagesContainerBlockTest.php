<?php

declare(strict_types=1);

/**
 * The container block (docs/magna-pages/12-BUILDER-REDESIGN.md §15).
 *
 * A container holds blocks in `children` and renders them through the SAME
 * partial every other block goes through — one rendering path, recursing
 * into itself. The claim worth testing is not that a wrapper element
 * appears: it is that nested content is indistinguishable from top-level
 * content everywhere it matters. Same view lookup, same style class, same
 * builder markers, same fragment output, same validation, same
 * authorization, same depth cap.
 *
 * A second nested renderer would pass a "does it render" test on day one
 * and drift from the published page by degrees afterwards, so every
 * assertion below compares nested behaviour against the top-level
 * behaviour it must equal.
 */

use Illuminate\Validation\ValidationException;
use Magna\Auth\Role;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\PageTreeAuthorizer;
use Magna\Blocks\PageTreeValidator;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function containerSetup(string ...$permissions): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant(...($permissions === []
        ? ['panel.access', 'pages.content', 'pages.layout', 'pages.design', 'pages.publish']
        : $permissions));
    $user->assignRole($role);

    return $user;
}

/**
 * A heading node, the simplest thing a container can hold.
 *
 * @return array<string, mixed>
 */
function containerChild(string $id, string $text): array
{
    return ['id' => $id, 'block' => 'heading', 'settings' => [], 'data' => ['text' => $text]];
}

/**
 * A container node.
 *
 * @param  list<array<string, mixed>>  $children
 * @param  array<string, mixed>  $settings
 * @return array<string, mixed>
 */
function containerNode(string $id, array $children, string $tag = 'div', array $settings = []): array
{
    return [
        'id' => $id,
        'block' => 'container',
        'settings' => $settings,
        'data' => ['tag' => $tag],
        'children' => $children,
    ];
}

/**
 * One section, one column, whatever blocks are given.
 *
 * @param  list<array<string, mixed>>  $blocks
 * @return array<mixed>
 */
function containerDocument(array $blocks): array
{
    return [[
        'id' => 'sec-c', 'type' => 'section', 'settings' => [],
        'columns' => [[
            'id' => 'col-c', 'span' => 12, 'settings' => [],
            'blocks' => $blocks,
        ]],
    ]];
}

/** @param array<mixed> $document */
function containerPage(User $author, string $slug, array $document, bool $publish = true): Entry
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Container page', 'slug' => $slug, 'blocks_data' => $document,
    ], $author->id);

    return $publish ? $manager->publish($entry, actorId: $author->id) : $entry;
}

/** A chain of containers `$depth` levels deep, with a heading at the bottom. */
function containerChain(int $depth): array
{
    $node = containerChild('blk-deepest', 'Bottom');

    for ($level = $depth; $level >= 1; $level--) {
        $node = containerNode("box-{$level}", [$node]);
    }

    return $node;
}

// ── The definition ───────────────────────────────────────────────────────────

it('registers a container block that declares it holds children', function (): void {
    containerSetup();

    $definition = app(BlockRegistry::class)->get('container');

    expect($definition)->not->toBeNull()
        ->and($definition->container)->toBeTrue()
        ->and($definition->category)->toBe('layout')
        // Every other block says no, so "container" is a capability the
        // registry answers rather than a handle anything hardcodes.
        ->and(app(BlockRegistry::class)->get('heading')?->container)->toBeFalse();
});

it('ships the container flag and the depth cap to the builder', function (): void {
    $author = containerSetup();
    $page = containerPage($author, 'container-bootstrap', containerDocument([]), publish: false);

    $payload = $this->actingAs($author)
        ->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()
        ->json();

    $container = collect($payload['registry'])->firstWhere('handle', 'container');

    expect($container['container'])->toBeTrue()
        ->and(collect($payload['registry'])->firstWhere('handle', 'heading')['container'])->toBeFalse()
        // Mirrored in TypeScript it would be a second source of truth.
        ->and($payload['maxBlockDepth'])->toBe(PageTreeValidator::MAX_BLOCK_DEPTH);

    // And what a fresh instance starts with, so the builder never seeds a
    // block the save would refuse for a missing required field.
    expect(collect($payload['registry'])->firstWhere('handle', 'button')['seed'])
        ->toMatchArray(['label' => 'Label', 'url' => '#']);
});

// ── Rendering ────────────────────────────────────────────────────────────────

it('renders a container with its children inside it', function (): void {
    $author = containerSetup();
    containerPage($author, 'container-basic', containerDocument([
        containerNode('box-1', [containerChild('blk-1', 'Inside the box')]),
    ]));

    $html = $this->get('/container-basic')->assertOk()->getContent();

    expect($html)->toContain('magna-container')
        ->and($html)->toContain('Inside the box');

    // Inside, not merely present: a container that rendered its children as
    // siblings would satisfy an assertSee and lay the page out wrongly.
    expect($html)->toMatch('/<div class="[^"]*magna-container[^"]*">.*Inside the box.*<\/div>/s');
});

it('renders every child of a container, in document order', function (): void {
    $author = containerSetup();
    containerPage($author, 'container-many', containerDocument([
        containerNode('box-1', [
            containerChild('blk-1', 'First child'),
            containerChild('blk-2', 'Second child'),
            containerChild('blk-3', 'Third child'),
        ]),
    ]));

    $html = $this->get('/container-many')->assertOk()->getContent();

    expect(strpos($html, 'First child'))->toBeLessThan(strpos($html, 'Second child'))
        ->and(strpos($html, 'Second child'))->toBeLessThan(strpos($html, 'Third child'));
});

it('renders containers inside containers', function (): void {
    $author = containerSetup();
    containerPage($author, 'container-nested', containerDocument([
        containerNode('box-outer', [
            containerChild('blk-1', 'Outer child'),
            containerNode('box-inner', [containerChild('blk-2', 'Inner child')], 'article'),
        ], 'section'),
    ]));

    $html = $this->get('/container-nested')->assertOk()->getContent();

    expect($html)->toContain('Outer child')
        ->and($html)->toContain('Inner child')
        ->and($html)->toContain('<section class="magna-block magna-block--container magna-container">')
        ->and($html)->toContain('<article class="magna-block magna-block--container magna-container">');
});

it('renders a chain nested to the maximum depth the validator allows', function (): void {
    $author = containerSetup();
    containerPage($author, 'container-deep', containerDocument([
        containerChain(PageTreeValidator::MAX_BLOCK_DEPTH - 1),
    ]));

    $html = $this->get('/container-deep')->assertOk()->getContent();

    expect($html)->toContain('Bottom')
        ->and(substr_count($html, 'magna-block--container'))
        ->toBe(PageTreeValidator::MAX_BLOCK_DEPTH - 1);
});

it('renders an empty container as an empty wrapper, not a hole in the page', function (): void {
    $author = containerSetup();
    containerPage($author, 'container-empty', containerDocument([
        containerNode('box-1', []),
        containerChild('blk-after', 'Still here'),
    ]));

    $html = $this->get('/container-empty')->assertOk()->getContent();

    expect($html)->toContain('magna-container--empty')
        // The block after it renders: an empty container is not an error.
        ->and($html)->toContain('Still here');

    // The empty-container affordance is a BUILDER one; the published page
    // gets the author's markup and nothing of it.
    expect($html)->not->toContain('outline: 1px dashed');
});

it('falls back to a div for a tag the allowlist does not name', function (): void {
    $author = containerSetup();
    containerPage($author, 'container-tag', containerDocument([
        containerNode('box-1', [containerChild('blk-1', 'Safe')], 'script'),
    ]));

    $html = $this->get('/container-tag')->assertOk()->getContent();

    // A document can never put an arbitrary tag name into the markup.
    expect($html)->toContain('<div class="magna-block magna-block--container magna-container">')
        ->and($html)->not->toContain('<script class=');
});

it('styles a nested block exactly as it styles a top-level one', function (): void {
    $author = containerSetup();
    containerPage($author, 'container-styles', containerDocument([
        containerNode(
            'box-1',
            [[
                'id' => 'blk-1', 'block' => 'heading',
                'settings' => ['style' => [
                    'fontSize' => '31px',
                    'paddingTop' => ['$responsive' => ['base' => '40px', 'mobile' => '9px']],
                ]],
                'data' => ['text' => 'Styled child'],
            ]],
            'div',
            ['style' => ['background' => 'var(--color-surface)']],
        ),
    ]));

    $html = $this->get('/container-styles')->assertOk()->getContent();

    // The nested block's rule reaches the page stylesheet and its class is
    // merged into the markup its own view produced.
    expect($html)->toContain('magna-n-blk-1')
        ->and($html)->toContain('font-size:31px')
        ->and($html)->toContain('padding-top:40px')
        ->and($html)->toContain('padding-top:9px !important')
        // And the container itself styles like any other block.
        ->and($html)->toContain('magna-n-box-1')
        ->and($html)->toContain('background-color:var(--color-surface)');
});

it('leaves the public page free of builder markers, nesting included', function (): void {
    $author = containerSetup();
    containerPage($author, 'container-public', containerDocument([
        containerNode('box-1', [containerChild('blk-1', 'Child')]),
    ]));

    expect($this->get('/container-public')->assertOk()->getContent())
        ->not->toContain('data-magna-node');
});

// ── The canvas and the fragment endpoint ─────────────────────────────────────

it('marks every nested node on the canvas, so a click maps back to it', function (): void {
    $author = containerSetup();
    $page = containerPage($author, 'container-canvas', containerDocument([
        containerNode('box-outer', [
            containerNode('box-inner', [containerChild('blk-1', 'Deep child')]),
        ]),
    ]), publish: false);

    $html = $this->actingAs($author)
        ->get(url('/pages-builder/'.$page->getKey().'/canvas'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-magna-node="box-outer" data-magna-kind="block"')
        ->and($html)->toContain('data-magna-node="box-inner" data-magna-kind="block"')
        ->and($html)->toContain('data-magna-node="blk-1" data-magna-kind="block"');
});

it('renders a container fragment identical to that node inside the full canvas', function (): void {
    $author = containerSetup();
    $document = containerDocument([
        containerNode('box-1', [
            containerChild('blk-1', 'First child'),
            containerChild('blk-2', 'Second child'),
        ]),
    ]);
    $page = containerPage($author, 'container-fragment', $document, publish: false);

    $canvas = $this->actingAs($author)
        ->get(url('/pages-builder/'.$page->getKey().'/canvas'))
        ->getContent();

    $fragment = $this->actingAs($author)->postJson(url('/pages-builder/'.$page->getKey().'/fragment'), [
        'node' => 'box-1',
        'document' => $document,
    ])->assertOk()->json('html');

    // The no-drift claim, for a node that has a subtree: a fragment-swapped
    // container carries its children, and carries them the way the full
    // render did. A fragment renderer of its own would swap the container
    // in EMPTY, which is exactly the bug this asserts against.
    expect(trim((string) $fragment))->toContain('First child')
        ->and(trim((string) $fragment))->toContain('Second child')
        ->and($canvas)->toContain(trim((string) $fragment));
});

it('renders a nested child as its own fragment', function (): void {
    $author = containerSetup();
    $document = containerDocument([
        containerNode('box-1', [containerChild('blk-1', 'Nested only')]),
    ]);
    $page = containerPage($author, 'container-child-fragment', $document, publish: false);

    $fragment = $this->actingAs($author)->postJson(url('/pages-builder/'.$page->getKey().'/fragment'), [
        'node' => 'blk-1',
        'document' => $document,
    ])->assertOk()->json('html');

    expect($fragment)->toContain('Nested only')
        ->and($fragment)->toContain('data-magna-node="blk-1"')
        // The child alone, not its container.
        ->and($fragment)->not->toContain('magna-container');
});

// ── Validation ───────────────────────────────────────────────────────────────

it('accepts nesting up to the cap and refuses one level past it', function (): void {
    containerSetup();
    $validator = app(PageTreeValidator::class);

    $atCap = containerDocument([containerChain(PageTreeValidator::MAX_BLOCK_DEPTH - 1)]);
    $overCap = containerDocument([containerChain(PageTreeValidator::MAX_BLOCK_DEPTH)]);

    expect($validator->validate($atCap))->toBeEmpty()
        ->and(implode(' ', $validator->validate($overCap)))->toContain('maximum nesting depth');
});

it('refuses a children key that is not a list of blocks', function (): void {
    containerSetup();
    $validator = app(PageTreeValidator::class);

    $notAList = containerDocument([[
        'id' => 'box-1', 'block' => 'container', 'settings' => [], 'data' => [],
        'children' => ['first' => ['id' => 'blk-1', 'block' => 'heading', 'data' => ['text' => 'x']]],
    ]]);
    $notAnArray = containerDocument([[
        'id' => 'box-2', 'block' => 'container', 'settings' => [], 'data' => [],
        'children' => 'nope',
    ]]);

    expect(implode(' ', $validator->validate($notAList)))->toContain('children must be an array of blocks')
        ->and(implode(' ', $validator->validate($notAnArray)))->toContain('children must be an array of blocks');

    // An empty list is a container that holds nothing, which is fine.
    expect($validator->validate(containerDocument([containerNode('box-3', [])])))->toBeEmpty();
});

it('validates a nested block the way it validates a top-level one', function (): void {
    containerSetup();
    $validator = app(PageTreeValidator::class);

    $missingRequired = containerDocument([
        containerNode('box-1', [['id' => 'blk-1', 'block' => 'heading', 'settings' => [], 'data' => []]]),
    ]);
    $unknownHandle = containerDocument([
        containerNode('box-1', [['id' => 'blk-1', 'block' => 'not_a_block', 'settings' => [], 'data' => []]]),
    ]);
    $duplicateId = containerDocument([containerNode('box-1', [containerChild('box-1', 'x')])]);

    expect(implode(' ', $validator->validate($missingRequired)))->toContain('Text field is required')
        ->and(implode(' ', $validator->validate($unknownHandle)))->toContain('is not registered')
        ->and(implode(' ', $validator->validate($duplicateId)))->toContain("Duplicate id 'box-1'");
});

it('refuses to save a document whose nesting is too deep', function (): void {
    $author = containerSetup();

    // Through the write path, not the validator alone: the rule has to be
    // enforced where documents actually arrive.
    expect(fn () => containerPage($author, 'container-too-deep', containerDocument([
        containerChain(PageTreeValidator::MAX_BLOCK_DEPTH),
    ])))->toThrow(ValidationException::class);
});

// ── Authorization ────────────────────────────────────────────────────────────

it('gates a raw-HTML block nested inside a container', function (): void {
    $author = containerSetup();
    $authorizer = app(PageTreeAuthorizer::class);

    $document = containerDocument([
        containerNode('box-outer', [
            containerNode('box-inner', [
                ['id' => 'blk-html', 'block' => 'html', 'settings' => [], 'data' => ['content' => '<script>alert(1)</script>']],
            ]),
        ]),
    ]);

    // Depth is no escape: the walk goes all the way down.
    expect(implode(' ', $authorizer->authorize($document, $author)))->toContain('blocks.raw_html');

    $trusted = containerSetup('panel.access', 'pages.content', 'pages.layout', 'blocks.raw_html');
    expect($authorizer->authorize($document, $trusted))->toBeEmpty();
});

it('refuses to insert a container carrying a block the actor may not use', function (): void {
    $author = containerSetup('panel.access', 'pages.content', 'pages.layout');
    $page = containerPage($author, 'container-patch-denied', containerDocument([]), publish: false);

    $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    $this->actingAs($author)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [[
            'op' => 'add',
            'path' => '/0/columns/0/blocks/-',
            'value' => containerNode('box-1', [
                ['id' => 'blk-html', 'block' => 'html', 'settings' => [], 'data' => ['content' => '<b>x</b>']],
            ]),
        ]],
    ])->assertStatus(422);

    expect($page->fresh()?->getAttribute('blocks_data')[0]['columns'][0]['blocks'])->toBeEmpty();
});

it('refuses a structural nested edit from an actor with only the content tier', function (): void {
    $author = containerSetup('panel.access', 'pages.content');
    $page = containerPage($author, 'container-content-tier', containerDocument([
        containerNode('box-1', [containerChild('blk-1', 'Child')]),
    ]), publish: false);

    $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    // Editing the nested block's TEXT is content and is allowed…
    $this->actingAs($author)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [[
            'op' => 'replace',
            'path' => '/0/columns/0/blocks/0/children/0/data/text',
            'value' => 'Edited',
        ]],
    ])->assertOk();

    // …deleting it is structure, and is not.
    $this->actingAs($author)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [['op' => 'remove', 'path' => '/0/columns/0/blocks/0/children/0']],
    ])->assertStatus(422);

    $stored = $page->fresh()?->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'][0]['children'])->toHaveCount(1)
        ->and($stored[0]['columns'][0]['blocks'][0]['children'][0]['data']['text'])->toBe('Edited');
});

it('refuses to move a container into its own descendant', function (): void {
    $author = containerSetup();
    $page = containerPage($author, 'container-cycle', containerDocument([
        containerNode('box-outer', [containerNode('box-inner', [])]),
    ]), publish: false);

    $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    // A cycle is not expressible: the destination lives inside what the
    // move lifts out, so by the time the add runs the path is gone.
    $this->actingAs($author)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [[
            'op' => 'move',
            'from' => '/0/columns/0/blocks/0',
            'path' => '/0/columns/0/blocks/0/children/0/children/0',
        ]],
    ])->assertStatus(422);

    $stored = $page->fresh()?->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'][0]['id'])->toBe('box-outer');
});

// ── Persistence and the round trip ───────────────────────────────────────────

it('stores and reloads a nested document unchanged', function (): void {
    $author = containerSetup();
    $document = containerDocument([
        containerNode('box-outer', [
            containerChild('blk-1', 'One'),
            containerNode('box-inner', [containerChild('blk-2', 'Two')], 'aside'),
        ]),
    ]);

    $page = containerPage($author, 'container-persist', $document, publish: false);
    $stored = $page->fresh()?->getAttribute('blocks_data');

    expect($stored)->toBe($document);
});

it('round-trips a nested edit through the builder API', function (): void {
    $author = containerSetup();
    $page = containerPage($author, 'container-roundtrip', containerDocument([
        containerNode('box-1', []),
        containerChild('blk-root', 'At the root'),
    ]), publish: false);

    $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    // Move the root block into the container, the way a drop does.
    $this->actingAs($author)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [[
            'op' => 'move',
            'from' => '/0/columns/0/blocks/1',
            'path' => '/0/columns/0/blocks/0/children/0',
        ]],
    ])->assertOk()
        ->assertJsonPath('document.0.columns.0.blocks.0.children.0.id', 'blk-root');

    // And back out again, keeping its id: a move is not a re-creation.
    $this->actingAs($author)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [[
            'op' => 'move',
            'from' => '/0/columns/0/blocks/0/children/0',
            'path' => '/0/columns/0/blocks/1',
        ]],
    ])->assertOk()
        ->assertJsonPath('document.0.columns.0.blocks.1.id', 'blk-root');

    $stored = $page->fresh()?->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'][0]['children'])->toBeEmpty()
        ->and($stored[0]['columns'][0]['blocks'][1]['data']['text'])->toBe('At the root');
});

it('bootstraps the builder with the nested document it stored', function (): void {
    $author = containerSetup();
    $document = containerDocument([
        containerNode('box-1', [containerChild('blk-1', 'Nested')]),
    ]);
    $page = containerPage($author, 'container-bootstrap-doc', $document, publish: false);

    $this->actingAs($author)
        ->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()
        ->assertJsonPath('document.blocks.0.columns.0.blocks.0.children.0.data.text', 'Nested');
});

it('keeps a nested page cacheable and byte-identical on the second hit', function (): void {
    $author = containerSetup();
    containerPage($author, 'container-cache', containerDocument([
        containerNode('box-1', [containerChild('blk-1', 'Cached child')]),
    ]));

    $first = $this->get('/container-cache')->assertOk()->assertHeader('X-Magna-Cache', 'miss')->getContent();
    $second = $this->get('/container-cache')->assertOk()->assertHeader('X-Magna-Cache', 'hit')->getContent();

    expect($second)->toBe($first);
});
