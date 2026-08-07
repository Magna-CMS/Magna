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
        ['align' => 'diagonal'], // missing required question + bad alignment
        'not-an-object',
    ]);

    expect(implode(' ', $errors))->toContain('missing Question')
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
        ->and($definition->validate([
            'items' => [['answer' => 'orphan answer']],
        ]))->toHaveKey('items');
});
