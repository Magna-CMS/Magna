<?php

declare(strict_types=1);

/**
 * The richtext sanitization boundary (10-REVIEW-RESOLUTIONS §C1): the text
 * block renders through an allowlist sanitizer and no longer requires
 * blocks.raw_html; only the html block stays permission-gated. Sanitization
 * runs at render (TextBlockResolver) AND at rest (BlocksField storage
 * transform) — one regression test per bypass class.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Auth\Role;
use Magna\Blocks\PageTreeAuthorizer;
use Magna\Blocks\Sanitization\RichTextSanitizer;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Sanitizer profile: one assertion per bypass class ────────────────────────

it('strips script elements', function (): void {
    $out = app(RichTextSanitizer::class)->sanitize('<p>ok</p><script>alert(1)</script>');

    expect($out)->toContain('<p>ok</p>')
        ->and($out)->not->toContain('script')
        ->and($out)->not->toContain('alert');
});

it('strips on* event handler attributes', function (): void {
    $out = app(RichTextSanitizer::class)->sanitize('<p onmouseover="alert(1)">hover</p><img src="https://example.com/a.png" onerror="alert(2)">');

    expect($out)->not->toContain('onmouseover')
        ->and($out)->not->toContain('onerror');
});

it('strips javascript: and data: URLs from links', function (): void {
    $out = app(RichTextSanitizer::class)->sanitize(
        '<a href="javascript:alert(1)">a</a><a href="data:text/html,x">b</a><a href="https://example.com">c</a>'
    );

    expect($out)->not->toContain('javascript:')
        ->and($out)->not->toContain('data:')
        ->and($out)->toContain('https://example.com');
});

it('neutralizes svg/foreignObject vectors', function (): void {
    $out = app(RichTextSanitizer::class)->sanitize(
        '<svg><foreignObject><iframe src="https://evil.example"></iframe></foreignObject></svg><p>keep</p>'
    );

    expect($out)->not->toContain('foreignObject')
        ->and($out)->not->toContain('iframe')
        ->and($out)->toContain('keep');
});

it('keeps ordinary formatting markup', function (): void {
    $in = '<h2>Title</h2><p><strong>Bold</strong> and <em>italic</em></p><ul><li>one</li></ul><blockquote>q</blockquote>';

    expect(app(RichTextSanitizer::class)->sanitize($in))
        ->toContain('<h2>Title</h2>')
        ->toContain('<strong>Bold</strong>')
        ->toContain('<em>italic</em>')
        ->toContain('<li>one</li>')
        ->toContain('<blockquote>q</blockquote>');
});

it('forces rel on links and does not truncate long content', function (): void {
    $sanitizer = app(RichTextSanitizer::class);

    expect($sanitizer->sanitize('<a href="https://example.com">x</a>'))->toContain('noopener noreferrer');

    $long = '<p>'.str_repeat('word ', 20000).'</p>'; // ~100k chars, past Symfony's 20k default
    expect(strlen($sanitizer->sanitize($long)))->toBeGreaterThan(90_000);
});

// ── Gate decoupling ──────────────────────────────────────────────────────────

it('no longer gates the text block behind blocks.raw_html', function (): void {
    expect(PageTreeAuthorizer::RAW_HTML_BLOCK_HANDLES)->toBe(['html']);

    $user = User::factory()->create(); // no roles, no permissions

    $doc = [[
        'id' => 'sec-1', 'type' => 'section', 'settings' => [],
        'columns' => [[
            'id' => 'col-1', 'span' => 12, 'settings' => [],
            'blocks' => [['id' => 'blk-1', 'block' => 'text', 'settings' => [], 'data' => ['body' => '<p>hi</p>']]],
        ]],
    ]];

    expect(app(PageTreeAuthorizer::class)->authorize($doc, $user))->toBeEmpty();
});

it('renders a text block sanitized through the preview for a user without blocks.raw_html', function (): void {
    $role = Role::factory()->create();
    $role->grant('blocks.preview'); // deliberately NOT blocks.raw_html
    $user = User::factory()->create();
    $user->assignRole($role);

    $blocksData = json_encode([[
        'id' => 'sec-1', 'type' => 'section', 'settings' => [],
        'columns' => [[
            'id' => 'col-1', 'span' => 12, 'settings' => [],
            'blocks' => [[
                'id' => 'blk-1', 'block' => 'text', 'settings' => [],
                'data' => ['body' => '<p>Safe <strong>copy</strong></p><script>alert(1)</script>'],
            ]],
        ]],
    ]]);

    $response = $this->actingAs($user)
        ->post(route('magna.blocks.preview'), ['blocks_data' => $blocksData])
        ->assertOk()
        ->assertSee('Safe', false)
        ->assertSee('<strong>copy</strong>', false);

    expect($response->getContent())->not->toContain('<script>');
});

// ── Sanitization at rest ─────────────────────────────────────────────────────

it('sanitizes text block bodies on save, including nested children', function (): void {
    $schemaRegistry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => 'sanitizer_page',
        'displayName' => 'Sanitizer Page',
        'localizable' => false,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'blocks_data', 'type' => 'blocks'],
        ],
    ], app(FieldTypeRegistry::class));
    $schemaRegistry->register($type);
    app(SchemaSyncer::class)->syncAll($schemaRegistry, allowDestructive: true);

    $author = User::factory()->create();

    $entry = app(EntryManager::class)->create('sanitizer_page', [
        'title' => 'Stored clean',
        'blocks_data' => [[
            'id' => 'sec-1', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-1', 'span' => 12, 'settings' => [],
                'blocks' => [[
                    'id' => 'blk-parent', 'block' => 'text', 'settings' => [],
                    'data' => ['body' => '<p>top</p><script>alert(1)</script>'],
                    'children' => [[
                        'id' => 'blk-child', 'block' => 'text', 'settings' => [],
                        'data' => ['body' => '<em>nested</em><img src="x" onerror="alert(2)">'],
                    ]],
                ]],
            ]],
        ]],
    ], $author->id);

    $read = Entry::type('sanitizer_page')->where($entry->getKeyName(), $entry->getKey())->firstOrFail();
    $stored = $read->getAttribute('blocks_data');

    $parentBody = $stored[0]['columns'][0]['blocks'][0]['data']['body'];
    $childBody = $stored[0]['columns'][0]['blocks'][0]['children'][0]['data']['body'];

    expect($parentBody)->toContain('<p>top</p>')
        ->and($parentBody)->not->toContain('script')
        ->and($childBody)->toContain('<em>nested</em>')
        ->and($childBody)->not->toContain('onerror');
});
