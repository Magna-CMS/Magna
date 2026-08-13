<?php

declare(strict_types=1);

/**
 * `inlineFields` on block.json: which of a block's fields may be edited
 * directly on the canvas, in the order the block wants them offered.
 *
 * Absent means "the first eligible field", which is what every block did
 * before the declaration existed — so this is additive for every block.json
 * already in the wild.
 */

use Magna\Blocks\BlockDefinition;

it('reads the declaration in the order the block gave it', function (): void {
    $definition = BlockDefinition::fromArray([
        'handle' => 'promo',
        'fields' => [
            ['handle' => 'headline', 'type' => 'text'],
            ['handle' => 'body', 'type' => 'richtext'],
        ],
        'inlineFields' => ['body', 'headline'],
    ]);

    expect($definition->inlineFields)->toBe(['body', 'headline']);
});

it('drops a handle the block does not have', function (): void {
    // A stale handle left after a field rename would otherwise be shipped
    // to a builder that would then look for a field that is not there.
    $definition = BlockDefinition::fromArray([
        'handle' => 'promo',
        'fields' => [['handle' => 'headline', 'type' => 'text']],
        'inlineFields' => ['headline', 'subtitle'],
    ]);

    expect($definition->inlineFields)->toBe(['headline']);
});

it('defaults to empty, which means the first eligible field', function (): void {
    $definition = BlockDefinition::fromArray([
        'handle' => 'promo',
        'fields' => [['handle' => 'headline', 'type' => 'text']],
    ]);

    expect($definition->inlineFields)->toBe([]);
});

it('ignores a malformed declaration rather than trusting it', function (): void {
    $definition = BlockDefinition::fromArray([
        'handle' => 'promo',
        'fields' => [['handle' => 'headline', 'type' => 'text']],
        'inlineFields' => ['headline', 42, ['nested']],
    ]);

    expect($definition->inlineFields)->toBe(['headline']);
});

it('keeps the declaration when a plugin is stamped on the definition', function (): void {
    $definition = BlockDefinition::fromArray([
        'handle' => 'promo',
        'fields' => [['handle' => 'headline', 'type' => 'text']],
        'inlineFields' => ['headline'],
    ])->withSourcePlugin('acme/promo');

    expect($definition->inlineFields)->toBe(['headline'])
        ->and($definition->sourcePlugin)->toBe('acme/promo');
});
