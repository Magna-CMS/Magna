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

// ── Relative URLs: the half the scheme allowlist silently ate ────────────────

/**
 * A relative URL has no scheme, and a scheme allowlist alone drops it. So
 * `<a href="/pricing">` was stored and rendered as `<a rel="noopener
 * noreferrer">` — the words survived, the destination did not, and nothing
 * anywhere said so. The element still looked like a link in the editor and
 * still rendered as an <a> on the page; it simply went nowhere.
 *
 * Linking to another page of your own site is the most ordinary thing a CMS
 * does. It failed silently every time, and the failure is invisible without
 * diffing rendered output by hand — which is why these cases exist.
 */
it('keeps a relative link to another page of the site', function (): void {
    $html = (new RichTextSanitizer)->sanitize('<a href="/pricing">See the plans</a>');

    expect($html)->toContain('href="/pricing"');
});

it('keeps an in-page anchor', function (): void {
    expect((new RichTextSanitizer)->sanitize('<a href="#faq">Jump</a>'))
        ->toContain('href="#faq"');
});

it('keeps a document-relative link', function (): void {
    expect((new RichTextSanitizer)->sanitize('<a href="./terms">Terms</a>'))
        ->toContain('href="./terms"');
});

/**
 * Same question for media: an image dragged out of the library carries
 * `/storage/...`, which was losing its src for the identical reason and
 * rendering as a blank box.
 */
it('keeps a relative image source', function (): void {
    $html = (new RichTextSanitizer)->sanitize('<img src="/storage/shot.png" alt="Shot">');

    expect($html)->toContain('src="/storage/shot.png"');
});

/**
 * And the reason this is safe: `javascript:` is not relative. It HAS a
 * scheme, that scheme is not on the allowlist, and allowing scheme-less URLs
 * does not allow it — including the tab-obfuscated spelling browsers would
 * otherwise execute.
 */
it('still refuses a script scheme in a link', function (): void {
    expect((new RichTextSanitizer)->sanitize('<a href="javascript:alert(1)">Bad</a>'))
        ->not->toContain('javascript');
});

it('still refuses a script scheme split by a tab', function (): void {
    expect((new RichTextSanitizer)->sanitize("<a href=\"java\tscript:alert(1)\">Bad</a>"))
        ->not->toContain('script:');
});

it('still refuses a script scheme in an image source', function (): void {
    expect((new RichTextSanitizer)->sanitize('<img src="javascript:alert(1)" alt="x">'))
        ->not->toContain('javascript');
});

/**
 * A protocol-relative URL is scheme-less, so it passes — deliberately, and
 * identically to Magna\Blocks\Support\SafeUrl, which guards the URL FIELDS
 * and returns the URL unchanged when parse_url reports no scheme. It is an
 * off-site destination rather than script execution, and the forced
 * rel="noopener noreferrer" still applies to it.
 */
it('treats a protocol-relative link the same way the URL fields do', function (): void {
    $html = (new RichTextSanitizer)->sanitize('<a href="//example.com">Out</a>');

    expect($html)->toContain('href="//example.com"')
        ->and($html)->toContain('rel="noopener noreferrer"');
});
