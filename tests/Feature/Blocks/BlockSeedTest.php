<?php

declare(strict_types=1);

/**
 * What a freshly inserted block starts with.
 *
 * Every save validates required fields, so a block whose required field
 * has no default is a block the builder cannot insert at all — the server
 * refuses the very patch that adds it, and the editor sees a block that
 * simply will not go on the page. That is exactly what happened to the
 * core `button` block (required `url`) and `entries` (required
 * `content_type`, whose options are dynamic and have no static default).
 *
 * The seed is computed from the schema on the server and shipped in the
 * bootstrap payload, so the builder never guesses. The guard below is the
 * point of this file: EVERY registered block must seed to data that
 * passes its own validation, so a block.json that forgets a default fails
 * here rather than in an editor's hands.
 */

use Magna\Blocks\BlockDefinition;
use Magna\Blocks\BlockRegistry;
use Magna\Content\ContentType;
use Magna\Content\SchemaRegistry;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A content type, so the `entries` block's dynamic select has something to
 * offer. Registered rather than migrated: what is under test is the seed,
 * not how types reach the registry.
 */
function seedContentType(string $handle = 'article', string $name = 'Article'): void
{
    app(SchemaRegistry::class)->register(new ContentType(
        handle: $handle,
        displayName: $name,
        localizable: false,
        draftable: true,
        fields: [],
    ));
}

it('seeds every registered block with data its own schema accepts', function (): void {
    seedContentType();

    /** @var BlockRegistry $registry */
    $registry = app(BlockRegistry::class);

    $refused = [];
    foreach ($registry->all() as $handle => $definition) {
        $errors = $definition->validate($definition->seedData());
        if ($errors !== []) {
            $refused[$handle] = $errors;
        }
    }

    // A block listed here cannot be inserted from the builder at all.
    expect($refused)->toBe([]);
});

it('seeds a required text field with its label, and a required url with a fragment', function (): void {
    /** @var BlockRegistry $registry */
    $registry = app(BlockRegistry::class);

    $seed = $registry->get('button')?->seedData() ?? [];

    expect($seed['label'])->toBe('Label')
        // Valid everywhere SafeUrl and the link validator look, and
        // visibly unfinished — which is what a placeholder should be.
        ->and($seed['url'])->toBe('#')
        // Declared defaults still win.
        ->and($seed['style'])->toBe('primary')
        ->and($seed['target'])->toBe('_self');
});

it('seeds a dynamic select from what this installation actually offers', function (): void {
    seedContentType('zebra', 'Zebra');
    seedContentType('aardvark', 'Aardvark');

    /** @var BlockRegistry $registry */
    $registry = app(BlockRegistry::class);

    // No static default is possible here: the options come from the
    // content types registered on THIS install, which is precisely why
    // the seed cannot be computed in the client. Options come sorted, so
    // the first one is the first alphabetically.
    expect($registry->get('entries')?->seedData()['content_type'])->toBe('aardvark');
});

it('leaves a dynamic select absent when the installation offers nothing', function (): void {
    // An `entries` block with no content types to point at is not
    // insertable, and inventing a handle for it would store a reference to
    // something that does not exist.
    expect(app(BlockRegistry::class)->get('entries')?->seedData())
        ->not->toHaveKey('content_type');
});

it('leaves optional fields, and types with no neutral value, absent', function (): void {
    $definition = BlockDefinition::fromArray([
        'handle' => 'seed_probe',
        'label' => 'Seed probe',
        'fields' => [
            ['handle' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => false],
            ['handle' => 'picture', 'type' => 'media', 'label' => 'Picture', 'required' => true],
            ['handle' => 'tint', 'type' => 'color', 'label' => 'Tint', 'required' => true],
            ['handle' => 'glyph', 'type' => 'icon', 'label' => 'Glyph', 'required' => true],
        ],
    ]);

    // Guessing a colour or an image and shipping it to every page is
    // worse than making the block author declare a default.
    expect($definition->seedData())->toBe([]);
});

it('seeds the remaining typed fields from what their own validator accepts', function (): void {
    $definition = BlockDefinition::fromArray([
        'handle' => 'seed_typed',
        'label' => 'Seed typed',
        'fields' => [
            ['handle' => 'align', 'type' => 'alignment', 'label' => 'Align', 'required' => true],
            ['handle' => 'href', 'type' => 'link', 'label' => 'Link', 'required' => true],
            ['handle' => 'count', 'type' => 'number', 'label' => 'Count', 'required' => true],
            [
                'handle' => 'size', 'type' => 'select', 'label' => 'Size', 'required' => true,
                'options' => ['sm' => 'Small', 'lg' => 'Large'],
            ],
        ],
    ]);

    expect($definition->seedData())->toBe([
        'align' => 'left',
        'href' => '#',
        'count' => 0,
        'size' => 'sm',
    ])->and($definition->validate($definition->seedData()))->toBe([]);
});
