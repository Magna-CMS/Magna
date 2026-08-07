<?php

declare(strict_types=1);

/**
 * The entries block's dynamic resolver â€” previously the block's view read a
 * `_resolved` payload nothing ever produced, so it always rendered its empty
 * state. Resolution now happens in the render path's resolve step
 * (BlockDataResolver) without ever mutating the stored document.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Auth\Role;
use Magna\Blocks\PageTree;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Blocks\Resolution\EntriesBlockResolver;
use Magna\Content\ContentType;
use Magna\Content\EntryManager;
use Magna\Content\EntryStatus;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function registerResolverArticleType(): void
{
    $schemaRegistry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => 'resolver_article',
        'displayName' => 'Resolver Article',
        'localizable' => false,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug'],
            ['handle' => 'excerpt', 'type' => 'textarea'],
        ],
    ], app(FieldTypeRegistry::class));

    $schemaRegistry->register($type);
    app(SchemaSyncer::class)->syncAll($schemaRegistry, allowDestructive: true);
}

function publishResolverArticle(string $title, ?string $publishedAt = null): void
{
    $author = User::factory()->create();

    $entry = app(EntryManager::class)->create('resolver_article', [
        'title' => $title,
        'excerpt' => "Excerpt for {$title}",
    ], $author->id);

    $entry->forceFill([
        'status' => EntryStatus::Published->value,
        'published_at' => $publishedAt ?? now()->toDateTimeString(),
    ])->save();
}

it('resolves published entries of the configured type, newest first', function (): void {
    registerResolverArticleType();
    publishResolverArticle('Older post', now()->subDay()->toDateTimeString());
    publishResolverArticle('Newer post');

    $resolved = app(EntriesBlockResolver::class)->resolve([
        'content_type' => 'resolver_article',
        'limit' => 6,
    ]);

    expect($resolved['entries'])->toHaveCount(2)
        ->and($resolved['entries'][0]['title'])->toBe('Newer post')
        ->and($resolved['entries'][1]['title'])->toBe('Older post')
        ->and($resolved['entries'][0]['excerpt'])->toBe('Excerpt for Newer post');
});

it('excludes drafts and honors the limit', function (): void {
    registerResolverArticleType();
    publishResolverArticle('Visible one');
    publishResolverArticle('Visible two');

    // A draft entry (default status) must never appear.
    $author = User::factory()->create();
    app(EntryManager::class)->create('resolver_article', ['title' => 'Hidden draft'], $author->id);

    $resolved = app(EntriesBlockResolver::class)->resolve([
        'content_type' => 'resolver_article',
        'limit' => 1,
    ]);

    $titles = array_column($resolved['entries'], 'title');

    expect($resolved['entries'])->toHaveCount(1)
        ->and($titles)->not->toContain('Hidden draft');
});

it('supports title sorting and falls back to published_at for unknown sorts', function (): void {
    registerResolverArticleType();
    publishResolverArticle('Bravo');
    publishResolverArticle('Alpha');

    $resolver = app(EntriesBlockResolver::class);

    $byTitle = $resolver->resolve(['content_type' => 'resolver_article', 'sort' => 'title_asc']);
    expect(array_column($byTitle['entries'], 'title'))->toBe(['Alpha', 'Bravo']);

    $fallback = $resolver->resolve(['content_type' => 'resolver_article', 'sort' => 'nonsense_key']);
    expect($fallback['entries'])->toHaveCount(2);
});

it('resolves an unknown content type to an empty list instead of failing', function (): void {
    $resolved = app(EntriesBlockResolver::class)->resolve([
        'content_type' => 'type_that_never_existed',
    ]);

    expect($resolved)->toBe(['entries' => []]);
});

it('clamps the limit to the hard cap', function (): void {
    registerResolverArticleType();
    publishResolverArticle('Single');

    $resolved = app(EntriesBlockResolver::class)->resolve([
        'content_type' => 'resolver_article',
        'limit' => 9999,
    ]);

    expect($resolved['entries'])->toHaveCount(1); // no crash, cap applied
});

it('leaves blocks without a registered resolver untouched and never mutates the document', function (): void {
    $tree = PageTree::fromArray([
        [
            'id' => 'sec-1', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-1', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-1', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Hi']]],
            ]],
        ],
    ]);

    $block = $tree->sections[0]->columns[0]->blocks[0];
    $payload = app(BlockDataResolver::class)->viewPayload($block);

    expect($payload)->not->toHaveKey('_resolved')
        ->and($block->toArray())->not->toHaveKey('_resolved');
});

it('renders resolved entries through the preview endpoint', function (): void {
    registerResolverArticleType();
    publishResolverArticle('Rendered headline');

    $role = Role::factory()->create();
    $role->grant('blocks.preview');
    $user = User::factory()->create();
    $user->assignRole($role);

    $blocksData = json_encode([[
        'id' => 'sec-1', 'type' => 'section', 'settings' => [],
        'columns' => [[
            'id' => 'col-1', 'span' => 12, 'settings' => [],
            'blocks' => [[
                'id' => 'blk-entries', 'block' => 'entries', 'settings' => [],
                'data' => ['content_type' => 'resolver_article', 'limit' => 6],
            ]],
        ]],
    ]]);

    $this->actingAs($user)
        ->post(route('magna.blocks.preview'), ['blocks_data' => $blocksData])
        ->assertOk()
        ->assertSee('Rendered headline');
});
