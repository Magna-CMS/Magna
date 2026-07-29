<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Shared helpers ────────────────────────────────────────────────────────────

function deliveryToken(): string
{
    $user = User::factory()->create();
    $result = $user->createToken('test-delivery', ['delivery'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $result->plainTextToken;
}

function registerArticleType(): ContentType
{
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => 'article',
        'displayName' => 'Article',
        'localizable' => false,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug', 'from' => 'title'],
            ['handle' => 'body', 'type' => 'textarea'],
        ],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    return $type;
}

function publishedArticle(array $attrs = []): Entry
{
    return Entry::type('article')->create(array_merge([
        'title' => 'Hello World',
        'slug' => 'hello-world',
        'status' => EntryStatus::Published,
        'locale' => '',
        'published_at' => now(),
    ], $attrs));
}

// ── 401 without token ─────────────────────────────────────────────────────────

it('returns 401 when no token is provided', function (): void {
    registerArticleType();

    $this->getJson('/api/v1/content/article')
        ->assertStatus(401);
});

// ── 404 for unknown type ──────────────────────────────────────────────────────

it('returns 404 for an unregistered content type', function (): void {
    $token = deliveryToken();

    $this->getJson('/api/v1/content/nonexistent', [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(404);
});

// ── Basic list ────────────────────────────────────────────────────────────────

it('lists published entries in the response envelope', function (): void {
    registerArticleType();
    publishedArticle(['title' => 'First']);
    publishedArticle(['title' => 'Second', 'slug' => 'second']);

    // Draft should NOT appear
    Entry::type('article')->create([
        'title' => 'Draft',
        'slug' => 'draft',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $token = deliveryToken();

    $response = $this->getJson('/api/v1/content/article', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['data', 'meta' => ['next_cursor', 'has_more', 'per_page'], 'included'])
        ->assertJsonCount(2, 'data');

    // Base fields always present
    $response->assertJsonPath('data.0.type', 'article')
        ->assertJsonPath('data.0.status', 'published');
});

// ── Draft invisible without preview token ─────────────────────────────────────

it('draft entries are invisible to the list endpoint', function (): void {
    registerArticleType();
    Entry::type('article')->create([
        'title' => 'Secret Draft',
        'slug' => 'secret',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $token = deliveryToken();

    $this->getJson('/api/v1/content/article', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');
});

// ── Field selection ───────────────────────────────────────────────────────────

it('field selection limits fields in the response', function (): void {
    registerArticleType();
    publishedArticle(['title' => 'Selective']);
    $token = deliveryToken();

    $response = $this->getJson('/api/v1/content/article?fields=title', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertStatus(200);

    $item = $response->json('data.0');
    expect($item)->toHaveKey('title');
    expect($item)->not->toHaveKey('body');
    // Base fields are always present
    expect($item)->toHaveKey('id');
    expect($item)->toHaveKey('status');
});

// ── Filter: safe operator ─────────────────────────────────────────────────────

it('filter[field][eq] narrows results correctly', function (): void {
    registerArticleType();
    publishedArticle(['title' => 'Alpha', 'slug' => 'alpha']);
    publishedArticle(['title' => 'Beta', 'slug' => 'beta']);
    $token = deliveryToken();

    $response = $this->getJson('/api/v1/content/article?filter[slug][eq]=alpha', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertStatus(200)->assertJsonCount(1, 'data');
    expect($response->json('data.0.slug'))->toBe('alpha');
});

// ── Filter: like (literal contains, escaped wildcards) ────────────────────────

it('filter[field][like] matches a literal substring', function (): void {
    registerArticleType();
    publishedArticle(['title' => 'Hello World', 'slug' => 'hello-world']);
    publishedArticle(['title' => 'Goodbye', 'slug' => 'goodbye']);
    $token = deliveryToken();

    $response = $this->getJson('/api/v1/content/article?filter[title][like]=ello', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertStatus(200)->assertJsonCount(1, 'data');
    expect($response->json('data.0.slug'))->toBe('hello-world');
});

it('filter[field][like] neutralises injected wildcards', function (): void {
    registerArticleType();
    publishedArticle(['title' => 'Fifty', 'slug' => 'fifty']);
    publishedArticle(['title' => 'Regular price', 'slug' => 'regular']);
    $token = deliveryToken();

    // 'F_fty' — if the underscore leaked through as a single-char wildcard,
    // LIKE '%F_fty%' would match 'Fifty'. Stripped to 'Ffty', it matches
    // nothing, proving the wildcard was neutralised.
    $response = $this->getJson('/api/v1/content/article?filter[title][like]=F_fty', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertStatus(200)->assertJsonCount(0, 'data');
});

it('rejects an over-long like filter value', function (): void {
    registerArticleType();
    $token = deliveryToken();

    $this->getJson('/api/v1/content/article?filter[title][like]='.str_repeat('a', 300), [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(400);
});

// ── Filter: injection rejected ────────────────────────────────────────────────

it('rejects an unknown filter operator', function (): void {
    registerArticleType();
    $token = deliveryToken();

    $this->getJson('/api/v1/content/article?filter[title][DROP]=1', [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(400);
});

it('rejects a filter on an unknown column', function (): void {
    registerArticleType();
    $token = deliveryToken();

    $this->getJson('/api/v1/content/article?filter[nonexistent_column][eq]=x', [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(400);
});

// ── Cursor pagination ─────────────────────────────────────────────────────────

it('cursor pagination returns the next page of results', function (): void {
    registerArticleType();

    for ($i = 1; $i <= 5; $i++) {
        publishedArticle(['title' => "Article {$i}", 'slug' => "article-{$i}"]);
    }

    $token = deliveryToken();

    // Page 1: per_page=2 → should have has_more=true
    $page1 = $this->getJson('/api/v1/content/article?per_page=2', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $page1->assertStatus(200)
        ->assertJsonPath('meta.has_more', true)
        ->assertJsonCount(2, 'data');

    $nextCursor = $page1->json('meta.next_cursor');
    expect($nextCursor)->not->toBeNull();

    // Page 2
    $page2 = $this->getJson("/api/v1/content/article?per_page=2&cursor={$nextCursor}", [
        'Authorization' => 'Bearer '.$token,
    ]);

    $page2->assertStatus(200)->assertJsonCount(2, 'data');

    // No overlap between pages
    $ids1 = collect($page1->json('data'))->pluck('id')->all();
    $ids2 = collect($page2->json('data'))->pluck('id')->all();
    expect(array_intersect($ids1, $ids2))->toBeEmpty();
});

it('last page has has_more=false', function (): void {
    registerArticleType();
    publishedArticle();
    $token = deliveryToken();

    $response = $this->getJson('/api/v1/content/article?per_page=10', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.next_cursor', null);
});

// ── Surrogate-Key header ─────────────────────────────────────────────────────

it('response carries a Surrogate-Key header with type and entry tokens', function (): void {
    registerArticleType();
    $entry = publishedArticle(['title' => 'Surrogate Test', 'slug' => 'surr']);
    $token = deliveryToken();

    $response = $this->getJson('/api/v1/content/article', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertStatus(200);
    $surrogateKeys = $response->headers->get('Surrogate-Key') ?? '';

    expect($surrogateKeys)->toContain('type:article');
    expect($surrogateKeys)->toContain('entry:'.$entry->id);
});

// ── ETag header on list ───────────────────────────────────────────────────────

it('response includes an ETag header', function (): void {
    registerArticleType();
    publishedArticle();
    $token = deliveryToken();

    $response = $this->getJson('/api/v1/content/article', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertStatus(200);
    expect($response->headers->has('ETag'))->toBeTrue();
});

// ── Query count budget ────────────────────────────────────────────────────────

it('list endpoint executes at most 4 queries with one relation type populated', function (): void {
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);
    $fieldTypes = app(FieldTypeRegistry::class);

    // Register a category type and an article type with a relation field
    $categoryType = ContentType::fromArray([
        'handle' => 'category',
        'displayName' => 'Category',
        'localizable' => false,
        'draftable' => false,
        'fields' => [['handle' => 'name', 'type' => 'text', 'required' => true]],
    ], $fieldTypes);
    $registry->register($categoryType);

    $articleType = ContentType::fromArray([
        'handle' => 'article',
        'displayName' => 'Article',
        'localizable' => false,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug'],
            ['handle' => 'category', 'type' => 'relation', 'to' => 'category'],
        ],
    ], $fieldTypes);
    $registry->register($articleType);

    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    // Create a published category
    $category = Entry::type('category')->create([
        'name' => 'Tech',
        'status' => EntryStatus::Published,
        'locale' => '',
        'published_at' => now(),
    ]);

    // Create published articles with a category relation
    for ($i = 1; $i <= 3; $i++) {
        $article = Entry::type('article')->create([
            'title' => "Article {$i}",
            'slug' => "article-{$i}",
            'status' => EntryStatus::Published,
            'locale' => '',
            'published_at' => now(),
        ]);
        DB::table('magna_relations')->insert([
            'id' => (string) Str::ulid(),
            'from_type' => 'article',
            'from_id' => $article->id,
            'to_type' => 'category',
            'to_id' => $category->id,
            'field' => 'category',
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $token = deliveryToken();

    DB::enableQueryLog();

    $response = $this->getJson('/api/v1/content/article?with=category', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertStatus(200)->assertJsonCount(3, 'data');

    // Count only magna_* queries (excludes token auth overhead: findToken, tokenable load, last_used_at update).
    // Expected: Q1=articles, Q2=pivot rows, Q3=categories (no media fields → no Q4).
    $contentQueries = array_filter(
        $queries,
        fn (array $q): bool => str_contains($q['query'], 'magna_'),
    );
    expect(count($contentQueries))->toBeLessThanOrEqual(4);
});
