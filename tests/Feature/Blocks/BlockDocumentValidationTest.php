<?php

declare(strict_types=1);

/**
 * Structural validator (recursive, auth-free) + actor authorizer split, and
 * the save-path wiring through BlocksField::validationRules().
 * See docs/magna-pages/10-REVIEW-RESOLUTIONS.md §A1/§C2.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Magna\Blocks\BlockDefinition;
use Magna\Blocks\PageTreeAuthorizer;
use Magna\Blocks\PageTreeValidator;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function validSection(string $id = 'sec-1', array $blocks = []): array
{
    return [
        'id' => $id,
        'type' => 'section',
        'settings' => [],
        'columns' => [
            ['id' => $id.'-col', 'span' => 12, 'settings' => [], 'blocks' => $blocks],
        ],
    ];
}

// ── Wrapped document shape ───────────────────────────────────────────────────

it('accepts the wrapped schemaVersion 1.0 document shape', function (): void {
    $validator = app(PageTreeValidator::class);

    $errors = $validator->validate([
        'schemaVersion' => '1.0',
        'sections' => [validSection()],
    ]);

    expect($errors)->toBeEmpty();
});

it('rejects a document with a newer schemaVersion instead of stripping it', function (): void {
    $validator = app(PageTreeValidator::class);

    $errors = $validator->validate([
        'schemaVersion' => '2.0',
        'sections' => [validSection()],
    ]);

    expect($errors)->not->toBeEmpty()
        ->and(implode(' ', $errors))->toContain("Unsupported document schemaVersion '2.0'");
});

it('rejects a wrapped document whose sections is not an array', function (): void {
    $validator = app(PageTreeValidator::class);

    $errors = $validator->validate(['schemaVersion' => '1.0', 'sections' => 'nope']);

    expect(implode(' ', $errors))->toContain("'sections' must be an array");
});

// ── Ref sections & unknown types ─────────────────────────────────────────────

it('accepts a ref section with a part reference and no columns', function (): void {
    $validator = app(PageTreeValidator::class);

    $errors = $validator->validate([
        ['id' => 'sec-hdr', 'type' => 'ref', 'part' => 'part-ulid'],
        validSection('sec-body'),
    ]);

    expect($errors)->toBeEmpty();
});

it('rejects a ref section without a part reference', function (): void {
    $validator = app(PageTreeValidator::class);

    $errors = $validator->validate([['id' => 'sec-hdr', 'type' => 'ref']]);

    expect(implode(' ', $errors))->toContain("missing a 'part' reference");
});

it('rejects an unknown section type', function (): void {
    $validator = app(PageTreeValidator::class);

    $errors = $validator->validate([['id' => 'sec-1', 'type' => 'hologram', 'columns' => []]]);

    expect(implode(' ', $errors))->toContain("unknown type 'hologram'");
});

// ── Recursive children ───────────────────────────────────────────────────────

it('validates nested children recursively (unregistered handle in a child)', function (): void {
    $validator = app(PageTreeValidator::class);

    $parent = [
        'id' => 'blk-parent', 'block' => 'text', 'settings' => [], 'data' => ['body' => 'x'],
        'children' => [
            ['id' => 'blk-child', 'block' => 'not_a_real_block', 'settings' => [], 'data' => []],
        ],
    ];

    $errors = $validator->validate([validSection('sec-1', [$parent])]);

    expect(implode(' ', $errors))->toContain('not_a_real_block');
});

it('detects duplicate ids inside nested children', function (): void {
    $validator = app(PageTreeValidator::class);

    $parent = [
        'id' => 'dup-id', 'block' => 'text', 'settings' => [], 'data' => ['body' => 'x'],
        'children' => [
            ['id' => 'dup-id', 'block' => 'text', 'settings' => [], 'data' => ['content' => 'y']],
        ],
    ];

    $errors = $validator->validate([validSection('sec-1', [$parent])]);

    expect(implode(' ', $errors))->toContain("Duplicate id 'dup-id'");
});

it('enforces the maximum block nesting depth', function (): void {
    $validator = app(PageTreeValidator::class);

    // Build a chain one level deeper than the cap (column-level block = depth 1).
    $node = ['id' => 'blk-deepest', 'block' => 'text', 'settings' => [], 'data' => ['body' => 'x']];
    for ($depth = PageTreeValidator::MAX_BLOCK_DEPTH; $depth >= 1; $depth--) {
        $node = [
            'id' => "blk-d{$depth}", 'block' => 'text', 'settings' => [], 'data' => ['body' => 'x'],
            'children' => [$node],
        ];
    }

    $errors = $validator->validate([validSection('sec-1', [$node])]);

    expect(implode(' ', $errors))->toContain('maximum nesting depth');
});

// ── Binding-aware field validation ───────────────────────────────────────────

it('treats a $bind value as satisfying a required block field', function (): void {
    $definition = BlockDefinition::fromArray([
        'handle' => 'bound_test',
        'label' => 'Bound',
        'fields' => [
            ['handle' => 'headline', 'type' => 'text', 'label' => 'Headline', 'required' => true],
        ],
    ]);

    expect($definition->validate(['headline' => ['$bind' => 'entry.title']]))->toBeEmpty()
        ->and($definition->validate([]))->not->toBeEmpty();
});

// ── Authorizer split ─────────────────────────────────────────────────────────

it('structural validator carries no raw-HTML permission errors', function (): void {
    $validator = app(PageTreeValidator::class);

    $doc = [validSection('sec-1', [
        ['id' => 'blk-html', 'block' => 'html', 'settings' => [], 'data' => ['content' => '<b>x</b>']],
    ])];

    expect(implode(' ', $validator->validate($doc)))->not->toContain('blocks.raw_html');
});

it('authorizer rejects raw-HTML blocks for an actor without the permission, including nested children', function (): void {
    $authorizer = app(PageTreeAuthorizer::class);
    $user = User::factory()->create();

    $doc = [validSection('sec-1', [
        [
            'id' => 'blk-wrap', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'x'],
            'children' => [
                ['id' => 'blk-html', 'block' => 'html', 'settings' => [], 'data' => ['content' => '<script>']],
            ],
        ],
    ])];

    $errors = $authorizer->authorize($doc, $user);

    expect(implode(' ', $errors))->toContain('blocks.raw_html');
});

it('authorizer allows a system context (null actor)', function (): void {
    $authorizer = app(PageTreeAuthorizer::class);

    $doc = [validSection('sec-1', [
        ['id' => 'blk-html', 'block' => 'html', 'settings' => [], 'data' => ['content' => '<b>x</b>']],
    ])];

    expect($authorizer->authorize($doc, null))->toBeEmpty();
});

// ── Save-path wiring (the gap this stage closes) ─────────────────────────────

function registerBlocksTestType(string $handle): void
{
    $schemaRegistry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => $handle,
        'displayName' => 'Doc Validation Page',
        'localizable' => false,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'blocks_data', 'type' => 'blocks'],
        ],
    ], app(FieldTypeRegistry::class));

    $schemaRegistry->register($type);
    app(SchemaSyncer::class)->syncAll($schemaRegistry, allowDestructive: true);
}

it('EntryManager rejects a structurally invalid block document on create', function (): void {
    registerBlocksTestType('doc_validation_page');
    $user = User::factory()->create();

    $invalid = [validSection('sec-1', [
        ['id' => 'blk-bad', 'block' => 'block_that_does_not_exist', 'settings' => [], 'data' => []],
    ])];

    expect(fn () => app(EntryManager::class)->create('doc_validation_page', [
        'title' => 'Broken',
        'blocks_data' => $invalid,
    ], $user->id))->toThrow(ValidationException::class);

    expect(Entry::type('doc_validation_page')->count())->toBe(0);
});

it('EntryManager persists a valid wrapped document unchanged (tolerant round-trip through save)', function (): void {
    registerBlocksTestType('doc_tolerant_page');
    $user = User::factory()->create();

    $document = [
        'schemaVersion' => '1.0',
        'futureRootKey' => ['kept' => true],
        'sections' => [validSection('sec-keep')],
    ];

    $entry = app(EntryManager::class)->create('doc_tolerant_page', [
        'title' => 'Kept',
        'blocks_data' => $document,
    ], $user->id);

    $read = Entry::type('doc_tolerant_page')->where($entry->getKeyName(), $entry->getKey())->firstOrFail();

    expect($read->getAttribute('blocks_data'))->toBe($document);
});
