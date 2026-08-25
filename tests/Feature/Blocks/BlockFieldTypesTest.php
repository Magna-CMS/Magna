<?php

declare(strict_types=1);

/**
 * The five builder-era block field types (10-REVIEW-RESOLUTIONS / plan §4.2):
 * link, repeater, icon, color, alignment — typed validation + editor support.
 * Legacy free-form types stay unvalidated so old stored content never starts
 * failing saves retroactively.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\Blocks\BlockDefinition;
use Magna\Blocks\BlockField;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\Livewire\BlockEditor;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function fieldOfType(string $type, array $extra = []): BlockField
{
    return BlockField::fromArray([
        'handle' => 'subject',
        'type' => $type,
        'label' => 'Subject',
        ...$extra,
    ]);
}

it('validates alignment values', function (): void {
    $field = fieldOfType('alignment');

    expect($field->validateValue('center'))->toBeEmpty()
        ->and($field->validateValue('justify'))->not->toBeEmpty();
});

it('validates color values as hex or token references', function (): void {
    $field = fieldOfType('color');

    expect($field->validateValue('#a1b2c3'))->toBeEmpty()
        ->and($field->validateValue('#abc'))->toBeEmpty()
        ->and($field->validateValue('token:primary'))->toBeEmpty()
        ->and($field->validateValue('red'))->not->toBeEmpty()
        ->and($field->validateValue('token:'))->not->toBeEmpty()
        ->and($field->validateValue('#zzz'))->not->toBeEmpty();
});

it('validates icon names', function (): void {
    $field = fieldOfType('icon');

    expect($field->validateValue('heroicon-o-sparkles'))->toBeEmpty()
        ->and($field->validateValue('<svg>'))->not->toBeEmpty();
});

it('validates links as safe URLs, relative paths, or entry references', function (): void {
    $field = fieldOfType('link');

    expect($field->validateValue('https://example.com/page'))->toBeEmpty()
        ->and($field->validateValue('/about'))->toBeEmpty()
        ->and($field->validateValue('#anchor'))->toBeEmpty()
        ->and($field->validateValue('mailto:hi@example.com'))->toBeEmpty()
        ->and($field->validateValue(['entry_type' => 'page', 'entry_id' => '01H...']))->toBeEmpty()
        ->and($field->validateValue('javascript:alert(1)'))->not->toBeEmpty()
        ->and($field->validateValue(['entry_type' => 'page']))->not->toBeEmpty();
});

it('validates repeater items against their item fields', function (): void {
    $field = fieldOfType('repeater', ['fields' => [
        ['handle' => 'question', 'type' => 'text', 'label' => 'Question', 'required' => true],
        ['handle' => 'align', 'type' => 'alignment', 'label' => 'Align'],
    ]]);

    expect($field->validateValue([
        ['question' => 'Why?', 'align' => 'left'],
    ]))->toBeEmpty();

    $errors = $field->validateValue([
        ['align' => 'diagonal'], // bad alignment, and no question yet
        'not-an-object',
    ]);

    /*
     * The missing required `question` is NOT an error any more.
     *
     * This asserted it until enforcing it per-save was found to make a
     * repeater unfillable: the builder writes on every keystroke, so every
     * row passes through incomplete on its way to being complete. Structure
     * and value rules still apply, which is what this now covers.
     */
    expect(implode(' ', $errors))->not->toContain('missing Question')
        ->and(implode(' ', $errors))->toContain('left, center, or right')
        ->and(implode(' ', $errors))->toContain('item #1 must be an object');
});

it('rejects nested repeaters at definition time', function (): void {
    expect(fn () => fieldOfType('repeater', ['fields' => [
        ['handle' => 'inner', 'type' => 'repeater', 'fields' => []],
    ]]))->toThrow(InvalidArgumentException::class, 'must not nest another repeater');
});

it('surfaces typed field errors through BlockDefinition::validate', function (): void {
    $definition = BlockDefinition::fromArray([
        'handle' => 'typed_test',
        'label' => 'Typed',
        'fields' => [
            ['handle' => 'accent', 'type' => 'color', 'label' => 'Accent'],
            ['handle' => 'target', 'type' => 'link', 'label' => 'Target'],
        ],
    ]);

    expect($definition->validate(['accent' => '#123456', 'target' => '/x']))->toBeEmpty()
        ->and($definition->validate(['accent' => 'nope']))->toHaveKey('accent')
        ->and($definition->validate(['target' => ['$bind' => 'entry.url']]))->toBeEmpty();
});

it('adds and removes repeater items in the editor with item defaults', function (): void {
    $document = (string) json_encode([[
        'id' => 'sec-1', 'type' => 'section', 'settings' => ['tokenOverrides' => []],
        'columns' => [[
            'id' => 'col-1', 'span' => 12, 'settings' => [],
            'blocks' => [[
                'id' => 'blk-faq', 'block' => 'faq', 'settings' => [], 'data' => ['items' => []],
            ]],
        ]],
    ]]);

    Livewire::actingAs(User::factory()->create())
        ->test(BlockEditor::class, ['blocksData' => $document])
        ->call('addRepeaterItem', 0, 0, 0, 'items')
        ->assertSet('sections.0.columns.0.blocks.0.data.items.0.question', '')
        ->assertSet('sections.0.columns.0.blocks.0.data.items.0.answer', '')
        ->call('addRepeaterItem', 0, 0, 0, 'items')
        ->call('removeRepeaterItem', 0, 0, 0, 'items', 0)
        ->assertCount('sections.0.columns.0.blocks.0.data.items', 1)
        // Guard: unknown field / non-repeater handles are no-ops.
        ->call('addRepeaterItem', 0, 0, 0, 'not_a_field')
        ->assertCount('sections.0.columns.0.blocks.0.data.items', 1);
});

it('accepts the converted faq block with valid items on save-path validation', function (): void {
    $definition = app(BlockRegistry::class)->get('faq');

    expect($definition)->not->toBeNull()
        ->and($definition->validate([
            'items' => [
                ['question' => 'What is Magna?', 'answer' => 'A CMS.'],
            ],
        ]))->toBeEmpty()
        // An answer with no question yet is a row mid-edit, not a mistake:
        // the same reason requiredness is no longer enforced per save.
        ->and($definition->validate([
            'items' => [['answer' => 'orphan answer']],
        ]))->toBeEmpty()
        // A shape that could never render still fails.
        ->and($definition->validate(['items' => ['not-an-object']]))->toHaveKey('items');
});

/*
 * Reported as: adding a question to a Styled FAQ answered
 * "The Questions field's item #0 is missing Question.", then — once that
 * was allowed — "missing Answer." the moment the question was typed.
 *
 * PageTreeValidator runs on every save and the builder saves on every
 * keystroke, so an incomplete row is the normal state of a row being
 * written. There is no keystroke order that reaches a complete row through
 * a validator that refuses every intermediate one.
 */
it('accepts a list row at every stage of being filled in', function (): void {
    $field = BlockField::fromArray([
        'handle' => 'items',
        'type' => 'repeater',
        'label' => 'Questions',
        'fields' => [
            ['handle' => 'question', 'type' => 'text', 'label' => 'Question', 'required' => true],
            ['handle' => 'answer', 'type' => 'textarea', 'label' => 'Answer', 'required' => true],
        ],
    ]);

    // Every state an editor passes through between pressing Add and
    // finishing the row.
    expect($field->validateValue([[]]))->toBe([])
        ->and($field->validateValue([['question' => '', 'answer' => '']]))->toBe([])
        ->and($field->validateValue([['question' => 'Do you ship?', 'answer' => '']]))->toBe([])
        ->and($field->validateValue([['question' => '', 'answer' => 'Yes.']]))->toBe([])
        ->and($field->validateValue([['question' => 'Do you ship?', 'answer' => 'Yes.']]))->toBe([]);
});

it('still checks the shape of a list and of the values in it', function (): void {
    // Requiredness is not enforced per keystroke, but structure still is —
    // it is what stops a crafted document reaching a view.
    $field = BlockField::fromArray([
        'handle' => 'items',
        'type' => 'repeater',
        'label' => 'Items',
        'fields' => [['handle' => 'tone', 'type' => 'color', 'label' => 'Tone']],
    ]);

    expect($field->validateValue('not a list'))->toBe(['The Items field must be a list of items.'])
        ->and($field->validateValue(['scalar']))->toBe(["The Items field's item #0 must be an object."])
        ->and($field->validateValue([['tone' => 'javascript:alert(1)']]))
        ->toBe(["The Items field's item #0: The Tone field must be a hex color (#rrggbb) or a design-token reference (token:name)."]);
});
