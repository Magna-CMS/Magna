<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Magna\Auth\Role;
use Magna\Blocks\BlockDefinition;
use Magna\Blocks\BlockField;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\Livewire\BlockEditor;
use Magna\Blocks\Options\ContentTypeOptions;
use Magna\Blocks\PageTree;
use Magna\Blocks\PageTreeValidator;
use Magna\Blocks\SectionNode;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeMinimalSection(string $id, array $columnSpans = [12], array $blocks = []): array
{
    $columns = [];
    foreach ($columnSpans as $i => $span) {
        $columns[] = [
            'id' => "col-{$id}-{$i}",
            'span' => $span,
            'settings' => [],
            'blocks' => $blocks,
        ];
    }

    return [
        'id' => $id,
        'type' => 'section',
        'settings' => ['tokenOverrides' => []],
        'columns' => $columns,
    ];
}

function makeBlock(string $id, string $handle, array $data = []): array
{
    return ['id' => $id, 'block' => $handle, 'settings' => [], 'data' => $data];
}

// ── PageTreeValidator ─────────────────────────────────────────────────────────

it('accepts a valid single-section full-width tree', function (): void {
    /** @var PageTreeValidator $validator */
    $validator = app(PageTreeValidator::class);

    $data = [makeMinimalSection('sec-1')];

    expect($validator->validate($data))->toBeEmpty();
});

it('rejects column spans that do not sum to 12', function (): void {
    /** @var PageTreeValidator $validator */
    $validator = app(PageTreeValidator::class);

    $data = [makeMinimalSection('sec-1', [6, 5])]; // 11 ≠ 12

    $errors = $validator->validate($data);

    expect($errors)->not->toBeEmpty()
        ->and(implode(' ', $errors))->toContain('sum to 11');
});

it('rejects duplicate section IDs', function (): void {
    /** @var PageTreeValidator $validator */
    $validator = app(PageTreeValidator::class);

    $data = [
        makeMinimalSection('same-id'),
        makeMinimalSection('same-id'),
    ];

    $errors = $validator->validate($data);

    expect($errors)->not->toBeEmpty()
        ->and(implode(' ', $errors))->toContain('same-id');
});

it('rejects duplicate block IDs across columns', function (): void {
    /** @var PageTreeValidator $validator */
    $validator = app(PageTreeValidator::class);

    $block = makeBlock('block-dup', 'heading', ['text' => 'Hello', 'level' => 'h2', 'align' => 'left']);
    $section = [
        'id' => 'sec-1',
        'type' => 'section',
        'settings' => [],
        'columns' => [
            ['id' => 'col-a', 'span' => 6, 'settings' => [], 'blocks' => [$block]],
            ['id' => 'col-b', 'span' => 6, 'settings' => [], 'blocks' => [$block]],
        ],
    ];

    $errors = $validator->validate([$section]);

    expect($errors)->not->toBeEmpty()
        ->and(implode(' ', $errors))->toContain('block-dup');
});

it('rejects an unregistered block handle on save', function (): void {
    /** @var PageTreeValidator $validator */
    $validator = app(PageTreeValidator::class);

    $section = makeMinimalSection('sec-1', [12], [makeBlock('b1', 'plugin_block_not_loaded')]);

    $errors = $validator->validate([$section]);

    expect($errors)->not->toBeEmpty()
        ->and(implode(' ', $errors))->toContain('plugin_block_not_loaded');
});

it('accepts a heading block with valid data', function (): void {
    /** @var PageTreeValidator $validator */
    $validator = app(PageTreeValidator::class);

    $block = makeBlock('b1', 'heading', ['text' => 'Hello', 'level' => 'h2', 'align' => 'left']);
    $section = makeMinimalSection('sec-1', [12], [$block]);

    expect($validator->validate([$section]))->toBeEmpty();
});

it('rejects tokenOverrides with empty key', function (): void {
    /** @var PageTreeValidator $validator */
    $validator = app(PageTreeValidator::class);

    $data = [
        [
            'id' => 'sec-1',
            'type' => 'section',
            'settings' => ['tokenOverrides' => ['' => '#fff']],
            'columns' => [['id' => 'col-1', 'span' => 12, 'settings' => [], 'blocks' => []]],
        ],
    ];

    $errors = $validator->validate($data);

    expect($errors)->not->toBeEmpty()
        ->and(implode(' ', $errors))->toContain('empty or non-string key');
});

it('rejects tokenOverrides with empty value', function (): void {
    /** @var PageTreeValidator $validator */
    $validator = app(PageTreeValidator::class);

    $data = [
        [
            'id' => 'sec-1',
            'type' => 'section',
            'settings' => ['tokenOverrides' => ['color-surface' => '']],
            'columns' => [['id' => 'col-1', 'span' => 12, 'settings' => [], 'blocks' => []]],
        ],
    ];

    $errors = $validator->validate($data);

    expect($errors)->not->toBeEmpty()
        ->and(implode(' ', $errors))->toContain('empty or non-string value');
});

// ── PageTree (read model) ────────────────────────────────────────────────────

it('round-trips a full tree through JSON without data loss', function (): void {
    $raw = [
        [
            'id' => 'sec-abc',
            'type' => 'section',
            'settings' => ['maxWidth' => 'xl', 'tokenOverrides' => ['color-brand' => '#6366f1']],
            'columns' => [
                [
                    'id' => 'col-abc',
                    'span' => 8,
                    'settings' => ['verticalAlign' => 'center'],
                    'blocks' => [
                        ['id' => 'blk-abc', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Hello', 'level' => 'h1', 'align' => 'left']],
                    ],
                ],
                [
                    'id' => 'col-def',
                    'span' => 4,
                    'settings' => [],
                    'blocks' => [],
                ],
            ],
        ],
    ];

    $tree = PageTree::fromArray($raw);
    $roundTrip = PageTree::fromJson($tree->toJson());

    expect($roundTrip->sections)->toHaveCount(1);
    $section = $roundTrip->sections[0];
    expect($section->id)->toBe('sec-abc')
        ->and($section->settings['maxWidth'])->toBe('xl')
        ->and($section->columns)->toHaveCount(2)
        ->and($section->columns[0]->span)->toBe(8)
        ->and($section->columns[0]->blocks[0]->block)->toBe('heading')
        ->and($section->columns[0]->blocks[0]->data['text'])->toBe('Hello');
});

it('tolerates an unknown block handle on read without throwing', function (): void {
    $json = json_encode([
        makeMinimalSection('sec-1', [12], [makeBlock('b1', 'future_plugin_block')]),
    ]);

    // Should not throw — unknown handles are skipped during read
    $tree = PageTree::fromJson((string) $json);

    expect($tree->sections[0]->columns[0]->blocks[0]->block)->toBe('future_plugin_block');
});

it('SectionNode::tokenOverrides() returns the keyed map from settings', function (): void {
    $section = SectionNode::fromArray([
        'id' => 'sec-1',
        'type' => 'section',
        'settings' => [
            'tokenOverrides' => [
                'color-surface' => '#ffffff',
                'spacing-lg' => '2rem',
            ],
        ],
        'columns' => [],
    ]);

    $overrides = $section->tokenOverrides();

    expect($overrides)->toBe([
        'color-surface' => '#ffffff',
        'spacing-lg' => '2rem',
    ]);
});

it('SectionNode::tokenOverrides() returns empty array when not set', function (): void {
    $section = SectionNode::fromArray([
        'id' => 'sec-1',
        'type' => 'section',
        'settings' => [],
        'columns' => [],
    ]);

    expect($section->tokenOverrides())->toBe([]);
});

// ── BlockRegistry ────────────────────────────────────────────────────────────

it('loads all 23 core blocks at boot', function (): void {
    /** @var BlockRegistry $registry */
    $registry = app(BlockRegistry::class);

    // 19 until `container` joined the standard library, then 21 with
    // `icon`, then 22 with `scheme-toggle`, then 23 with `logo`. The number is asserted so a block.json that fails to parse
    // cannot disappear silently — update it deliberately when the library
    // grows, never to make a red test green.
    expect($registry->count())->toBe(23)
        ->and($registry->has('container'))->toBeTrue()
        ->and($registry->has('icon'))->toBeTrue()
        ->and($registry->has('scheme-toggle'))->toBeTrue()
        ->and($registry->has('logo'))->toBeTrue();
});

it('has() returns true for registered core blocks and false for unknown', function (): void {
    /** @var BlockRegistry $registry */
    $registry = app(BlockRegistry::class);

    expect($registry->has('heading'))->toBeTrue()
        ->and($registry->has('cta'))->toBeTrue()
        ->and($registry->has('entries'))->toBeTrue()
        ->and($registry->has('not_a_real_block'))->toBeFalse();
});

it('register() adds a plugin-provided block to the registry', function (): void {
    /** @var BlockRegistry $registry */
    $registry = app(BlockRegistry::class);

    $before = $registry->count();

    $pluginBlock = BlockDefinition::fromArray([
        'handle' => 'contact-form',
        'label' => 'Contact Form',
        'icon' => 'heroicon-o-envelope',
        'category' => 'forms',
        'fields' => [],
    ]);

    $registry->register($pluginBlock);

    expect($registry->count())->toBe($before + 1)
        ->and($registry->has('contact-form'))->toBeTrue()
        ->and($registry->get('contact-form'))->toBe($pluginBlock);
});

it('grouped() returns blocks keyed by category', function (): void {
    /** @var BlockRegistry $registry */
    $registry = app(BlockRegistry::class);

    $grouped = $registry->grouped();

    expect($grouped)->toBeArray()
        ->and($grouped)->toHaveKey('content')
        ->and($grouped)->toHaveKey('marketing')
        ->and($grouped)->toHaveKey('media');
});

// ── BlockDefinition ───────────────────────────────────────────────────────────

it('validate() returns no errors when required fields are present', function (): void {
    $definition = BlockDefinition::fromArray([
        'handle' => 'heading',
        'label' => 'Heading',
        'fields' => [
            ['handle' => 'text', 'type' => 'text', 'label' => 'Text', 'required' => true],
            ['handle' => 'level', 'type' => 'select', 'label' => 'Level'],
        ],
    ]);

    $errors = $definition->validate(['text' => 'Hello World', 'level' => 'h2']);

    expect($errors)->toBeEmpty();
});

it('validate() returns errors when required fields are missing', function (): void {
    $definition = BlockDefinition::fromArray([
        'handle' => 'heading',
        'label' => 'Heading',
        'fields' => [
            ['handle' => 'text', 'type' => 'text', 'label' => 'Text', 'required' => true],
        ],
    ]);

    $errors = $definition->validate([]);

    expect($errors)->toHaveKey('text');
});

// ── BlockField — optionsFrom (ProvidesOptions) ────────────────────────────────

it('BlockField::resolveOptions() delegates to a ProvidesOptions class when optionsFrom is set', function (): void {
    $field = BlockField::fromArray([
        'handle' => 'content_type',
        'type' => 'select',
        'label' => 'Content type',
        'optionsFrom' => ContentTypeOptions::class,
    ]);

    // ContentTypeOptions reads SchemaRegistry::all() — empty at boot, so we
    // register a type first so the options list is non-trivially verifiable.
    /** @var SchemaRegistry $schemaRegistry */
    $schemaRegistry = app(SchemaRegistry::class);
    $schemaRegistry->register(
        ContentType::fromArray(
            ['handle' => 'post', 'displayName' => 'Blog Post', 'localizable' => false, 'draftable' => false, 'fields' => []],
            app(FieldTypeRegistry::class)
        )
    );

    $options = $field->resolveOptions();

    expect($options)->toBeArray()
        ->and($options)->toHaveKey('post')
        ->and($options['post'])->toBe('Blog Post');
});

it('BlockField::resolveOptions() returns static options when optionsFrom is null', function (): void {
    $field = BlockField::fromArray([
        'handle' => 'level',
        'type' => 'select',
        'label' => 'Level',
        'options' => ['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3'],
    ]);

    expect($field->resolveOptions())->toBe(['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3']);
});

// ── BlockEditor — tokenOverrides normalisation ────────────────────────────────

it('BlockEditor::serialise() converts [{key,value}] array to {key:value} map', function (): void {
    $editor = new BlockEditor;
    $editor->mount(json_encode([
        [
            'id' => 'sec-1',
            'type' => 'section',
            'settings' => [
                // stored format (keyed map) — mount() converts to editor format
                'tokenOverrides' => ['color-brand' => '#6366f1', 'font-size-base' => '16px'],
            ],
            'columns' => [['id' => 'col-1', 'span' => 12, 'settings' => [], 'blocks' => []]],
        ],
    ]));

    // After mount, sections should have the array-of-objects format for the editor
    $editorSection = $editor->sections[0];
    expect($editorSection['settings']['tokenOverrides'])->toBeArray()
        ->and($editorSection['settings']['tokenOverrides'][0])->toBe(['key' => 'color-brand', 'value' => '#6366f1'])
        ->and($editorSection['settings']['tokenOverrides'][1])->toBe(['key' => 'font-size-base', 'value' => '16px']);

    // After serialise(), the JSON should have the keyed map format
    $json = $editor->serialise();
    $parsed = json_decode($json, true);

    expect($parsed[0]['settings']['tokenOverrides'])->toBe([
        'color-brand' => '#6366f1',
        'font-size-base' => '16px',
    ]);
});

it('BlockEditor::serialise() filters out tokenOverride pairs with empty keys or values', function (): void {
    $editor = new BlockEditor;
    $editor->mount('[]');

    // Simulate the editor state with some incomplete overrides
    $editor->sections = [
        [
            'id' => 'sec-1',
            'type' => 'section',
            'settings' => [
                'tokenOverrides' => [
                    ['key' => 'color-brand', 'value' => '#fff'],
                    ['key' => '',            'value' => 'should be dropped'],
                    ['key' => 'font-size',   'value' => ''],
                    ['key' => 'valid-key',   'value' => '1rem'],
                ],
            ],
            'columns' => [['id' => 'col-1', 'span' => 12, 'settings' => [], 'blocks' => []]],
        ],
    ];

    $parsed = json_decode($editor->serialise(), true);
    $overrides = $parsed[0]['settings']['tokenOverrides'];

    expect($overrides)->toBe([
        'color-brand' => '#fff',
        'valid-key' => '1rem',
    ]);
});

// ── Block preview endpoint ────────────────────────────────────────────────────

it('preview endpoint returns 200 with semantic section HTML', function (): void {
    $role = Role::factory()->create();
    $role->grant('blocks.preview');
    $user = User::factory()->create();
    $user->assignRole($role);

    $blocksData = json_encode([
        [
            'id' => 'sec-preview',
            'type' => 'section',
            'settings' => ['tokenOverrides' => ['color-surface' => '#0a0a0a']],
            'columns' => [
                [
                    'id' => 'col-preview',
                    'span' => 12,
                    'settings' => [],
                    'blocks' => [
                        ['id' => 'blk-preview', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Preview Test', 'level' => 'h1', 'align' => 'left']],
                    ],
                ],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->post(route('magna.blocks.preview'), ['blocks_data' => $blocksData])
        ->assertOk()
        ->assertSee('<section', false)
        ->assertSee('sec-preview', false)
        ->assertSee('--color-surface:#0a0a0a', false)
        ->assertSee('Preview Test', false);
});

it('preview endpoint returns empty message when blocks_data is an empty array', function (): void {
    $role = Role::factory()->create();
    $role->grant('blocks.preview');
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->post(route('magna.blocks.preview'), ['blocks_data' => '[]'])
        ->assertOk()
        ->assertSee('No sections in this entry', false);
});

// Stage 7: the preview route was previously reachable by any authenticated
// user with no permission check at all, contradicting the html/text block
// templates' own "restricted to admin/editor roles only" comment, and
// PageTree::fromJson() was never validated (unregistered handles rendered
// unchecked).

it('preview endpoint rejects a user without blocks.preview', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('magna.blocks.preview'), ['blocks_data' => '[]'])
        ->assertForbidden();
});

it('preview endpoint rejects an html block from a user without blocks.raw_html', function (): void {
    $role = Role::factory()->create();
    $role->grant('blocks.preview');
    $user = User::factory()->create();
    $user->assignRole($role);

    $blocksData = json_encode([[
        'id' => 'sec-1', 'type' => 'section', 'settings' => ['tokenOverrides' => []],
        'columns' => [[
            'id' => 'col-1', 'span' => 12, 'settings' => [],
            'blocks' => [['id' => 'blk-1', 'block' => 'html', 'settings' => [], 'data' => ['content' => '<script>alert(1)</script>']]],
        ]],
    ]]);

    $response = $this->actingAs($user)
        ->post(route('magna.blocks.preview'), ['blocks_data' => $blocksData]);

    $response->assertStatus(422);
    expect($response->getContent())->not->toContain('<script>alert(1)</script>');
});

it('preview endpoint allows an html block from a user with blocks.raw_html', function (): void {
    $role = Role::factory()->create();
    $role->grant('blocks.preview', 'blocks.raw_html');
    $user = User::factory()->create();
    $user->assignRole($role);

    $blocksData = json_encode([[
        'id' => 'sec-1', 'type' => 'section', 'settings' => ['tokenOverrides' => []],
        'columns' => [[
            'id' => 'col-1', 'span' => 12, 'settings' => [],
            'blocks' => [['id' => 'blk-1', 'block' => 'html', 'settings' => [], 'data' => ['content' => '<p>trusted content</p>']]],
        ]],
    ]]);

    $this->actingAs($user)
        ->post(route('magna.blocks.preview'), ['blocks_data' => $blocksData])
        ->assertOk()
        ->assertSee('trusted content', false);
});

it('preview endpoint rejects an unregistered block handle', function (): void {
    $role = Role::factory()->create();
    $role->grant('blocks.preview');
    $user = User::factory()->create();
    $user->assignRole($role);

    $blocksData = json_encode([[
        'id' => 'sec-1', 'type' => 'section', 'settings' => ['tokenOverrides' => []],
        'columns' => [[
            'id' => 'col-1', 'span' => 12, 'settings' => [],
            'blocks' => [['id' => 'blk-1', 'block' => 'not-a-real-block', 'settings' => [], 'data' => []]],
        ]],
    ]]);

    $this->actingAs($user)
        ->post(route('magna.blocks.preview'), ['blocks_data' => $blocksData])
        ->assertStatus(422);
});

it('preview endpoint redirects unauthenticated requests', function (): void {
    // Filament registers its login page but not a named 'login' route.
    // Assert that an unauthenticated request is refused (not 200).
    $response = $this->post(route('magna.blocks.preview'), ['blocks_data' => '[]']);

    expect($response->status())->not->toBe(200);
});

// ── EntryManager round-trip with blocks field ─────────────────────────────────

it('EntryManager round-trips a blocks_data column through create → read', function (): void {
    /** @var SchemaRegistry $schemaRegistry */
    $schemaRegistry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => 'page',
        'displayName' => 'Page',
        'localizable' => false,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title',       'type' => 'text', 'required' => true],
            ['handle' => 'blocks_data', 'type' => 'blocks'],
        ],
    ], app(FieldTypeRegistry::class));

    $schemaRegistry->register($type);
    app(SchemaSyncer::class)->syncAll($schemaRegistry, allowDestructive: true);

    $user = User::factory()->create();

    $tree = [
        [
            'id' => 'sec-rt',
            'type' => 'section',
            'settings' => ['tokenOverrides' => ['color-brand' => '#6366f1']],
            'columns' => [
                [
                    'id' => 'col-rt',
                    'span' => 12,
                    'settings' => [],
                    'blocks' => [
                        // 'body' is the text block's real (required) field handle —
                        // save-path validation now enforces block field rules.
                        ['id' => 'blk-rt', 'block' => 'text', 'settings' => [], 'data' => ['body' => 'Hello world']],
                    ],
                ],
            ],
        ],
    ];

    /** @var EntryManager $manager */
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Home',
        'blocks_data' => $tree,
    ], $user->id);

    // Re-fetch from DB using Entry::type() query builder
    $read = Entry::type('page')->where($entry->getKeyName(), $entry->getKey())->first();

    expect($read)->not->toBeNull();

    $readTree = $read->blocks_data;

    expect($readTree)->toBeArray()
        ->and($readTree[0]['id'])->toBe('sec-rt')
        ->and($readTree[0]['settings']['tokenOverrides']['color-brand'])->toBe('#6366f1')
        ->and($readTree[0]['columns'][0]['blocks'][0]['data']['body'])->toBe('Hello world');
});
