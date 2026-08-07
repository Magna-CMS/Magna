<?php

declare(strict_types=1);

/**
 * Golden-file round-trip suite for the tolerant block-document layer.
 *
 * The load-bearing guarantee (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §A1):
 * parsing a document through the PageTree value objects and serialising it
 * back is lossless for every format-v1 construct AND for unknown future
 * keys. Each case asserts exact array equality — a dropped key here is the
 * silent-data-loss bug class the rewrite exists to kill.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Blocks\PageTree;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Each dataset entry is wrapped in a single-element list so Pest passes the
 * whole document as one argument instead of spreading it.
 *
 * @return array<string, array{0: array<mixed, mixed>}>
 */
function goldenDocuments(): array
{
    $documents = [
        'legacy list of plain sections' => [
            [
                'id' => 'sec-1',
                'type' => 'section',
                'settings' => ['background' => ['type' => 'color', 'value' => '#fff']],
                'columns' => [
                    [
                        'id' => 'col-1',
                        'span' => 12,
                        'settings' => [],
                        'blocks' => [
                            ['id' => 'blk-1', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Hello']],
                        ],
                    ],
                ],
            ],
        ],
        'wrapped document with schemaVersion' => [
            'schemaVersion' => '1.0',
            'sections' => [
                [
                    'id' => 'sec-1',
                    'type' => 'section',
                    'settings' => [],
                    'columns' => [
                        ['id' => 'col-1', 'span' => 12, 'settings' => [], 'blocks' => []],
                    ],
                ],
            ],
        ],
        'container block with nested children' => [
            [
                'id' => 'sec-1',
                'type' => 'section',
                'settings' => [],
                'columns' => [
                    [
                        'id' => 'col-1',
                        'span' => 12,
                        'settings' => [],
                        'blocks' => [
                            [
                                'id' => 'blk-tabs',
                                'block' => 'container',
                                'settings' => ['direction' => 'row'],
                                'data' => [],
                                'children' => [
                                    ['id' => 'blk-child-1', 'block' => 'text', 'settings' => [], 'data' => ['body' => 'A']],
                                    [
                                        'id' => 'blk-child-2',
                                        'block' => 'container',
                                        'settings' => [],
                                        'data' => [],
                                        'children' => [
                                            ['id' => 'blk-grandchild', 'block' => 'image', 'settings' => [], 'data' => ['media' => 'm1']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'ref section pointing at a template part' => [
            ['id' => 'sec-hdr', 'type' => 'ref', 'part' => '01HXPARTULID000000000000ID'],
            [
                'id' => 'sec-body',
                'type' => 'section',
                'settings' => [],
                'columns' => [
                    ['id' => 'col-1', 'span' => 12, 'settings' => [], 'blocks' => []],
                ],
            ],
        ],
        'field bindings and inline conditions' => [
            [
                'id' => 'sec-1',
                'type' => 'section',
                'settings' => [],
                'conditions' => [['key' => 'auth', 'operator' => 'is', 'value' => true]],
                'columns' => [
                    [
                        'id' => 'col-1',
                        'span' => 12,
                        'settings' => [],
                        'blocks' => [
                            [
                                'id' => 'blk-1',
                                'block' => 'heading',
                                'settings' => [],
                                'data' => ['text' => ['$bind' => 'entry.title']],
                                'bindings' => ['text' => 'entry.title'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'per-breakpoint responsive setting maps' => [
            [
                'id' => 'sec-1',
                'type' => 'section',
                'settings' => [
                    'padding' => ['base' => '2rem', 'md' => '4rem', 'lg' => '6rem'],
                    'visibility' => ['desktop' => true, 'tablet' => true, 'mobile' => false],
                ],
                'columns' => [
                    [
                        'id' => 'col-1',
                        'span' => 12,
                        'settings' => ['padding' => ['base' => ['x' => '1rem', 'y' => '0']]],
                        'blocks' => [],
                    ],
                ],
            ],
        ],
        'unknown future keys at every level' => [
            'schemaVersion' => '1.0',
            'localeOverlays' => ['de' => ['blk-1' => ['text' => 'Hallo']]],
            'futureRootKey' => ['anything' => [1, 2, 3]],
            'sections' => [
                [
                    'id' => 'sec-1',
                    'type' => 'section',
                    'settings' => [],
                    'futureSectionKey' => 'preserved',
                    'columns' => [
                        [
                            'id' => 'col-1',
                            'span' => 12,
                            'settings' => [],
                            'futureColumnKey' => ['nested' => true],
                            'blocks' => [
                                [
                                    'id' => 'blk-1',
                                    'block' => 'text',
                                    'settings' => [],
                                    'data' => ['body' => 'x'],
                                    'futureBlockKey' => 42,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    return array_map(static fn (array $document): array => [$document], $documents);
}

it('round-trips every golden document without loss (array identity)', function (array $document): void {
    expect(PageTree::fromArray($document)->toArray())->toBe($document);
})->with(goldenDocuments());

it('round-trips every golden document through JSON byte-identically', function (array $document): void {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    $json = (string) json_encode($document, $flags);

    expect(PageTree::fromJson($json)->toJson())->toBe($json);
})->with(goldenDocuments());

it('preserves non-array entries in node lists verbatim', function (): void {
    $document = [
        [
            'id' => 'sec-1',
            'type' => 'section',
            'settings' => [],
            'columns' => [
                [
                    'id' => 'col-1',
                    'span' => 12,
                    'settings' => [],
                    'blocks' => [
                        ['id' => 'blk-1', 'block' => 'text', 'settings' => [], 'data' => []],
                        'stray-string-entry',
                    ],
                ],
            ],
        ],
    ];

    expect(PageTree::fromArray($document)->toArray())->toBe($document);
});

it('exposes parsed ref-section helpers', function (): void {
    $tree = PageTree::fromArray([
        ['id' => 'sec-hdr', 'type' => 'ref', 'part' => 'part-ulid'],
    ]);

    expect($tree->sections[0]->isRef())->toBeTrue()
        ->and($tree->sections[0]->part())->toBe('part-ulid');
});

it('exposes nested children as parsed nodes', function (): void {
    $tree = PageTree::fromArray([
        [
            'id' => 'sec-1',
            'type' => 'section',
            'settings' => [],
            'columns' => [
                [
                    'id' => 'col-1',
                    'span' => 12,
                    'settings' => [],
                    'blocks' => [
                        [
                            'id' => 'blk-parent',
                            'block' => 'container',
                            'settings' => [],
                            'data' => [],
                            'children' => [
                                ['id' => 'blk-child', 'block' => 'text', 'settings' => [], 'data' => ['body' => 'x']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $parent = $tree->sections[0]->columns[0]->blocks[0];
    expect($parent->children)->toHaveCount(1)
        ->and($parent->children[0]->block)->toBe('text');
});

it('reads schemaVersion from the wrapped form and null from the legacy form', function (): void {
    expect(PageTree::fromArray(['schemaVersion' => '1.0', 'sections' => []])->schemaVersion)->toBe('1.0')
        ->and(PageTree::fromArray([])->schemaVersion)->toBeNull();
});
