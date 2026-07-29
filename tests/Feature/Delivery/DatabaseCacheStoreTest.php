<?php

declare(strict_types=1);

use Illuminate\Cache\TaggableStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Delivery\ETagService;
use Magna\Delivery\ResponseCacheService;
use Magna\Delivery\TagAwareCache;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Regression tests for the SHIPPED DEFAULT cache store: `database`.
 *
 * The rest of the suite runs on the `array` store (phpunit.xml), which IS
 * taggable — so Cache::tags() there never threw, which is exactly what hid the
 * original release-blocker: on the default `database` store Cache::tags()
 * throws "This cache store does not support tagging." and every delivery
 * request 500'd on a fresh install. These tests pin the delivery cache to the
 * default store so the non-taggable path (TagAwareCache generation-key
 * fallback) is always exercised.
 */

beforeEach(function (): void {
    config(['cache.default' => 'database']);
    Cache::purge('database'); // drop any resolved driver so it rebuilds against the DB
    Cache::store('database')->clear();

    // Guard: if this ever becomes taggable the tests below stop proving anything.
    expect(Cache::getStore())->not->toBeInstanceOf(TaggableStore::class);
});

function dbStoreArticleType(): ContentType
{
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => 'dbc_article',
        'displayName' => 'DB Cache Article',
        'localizable' => false,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug', 'from' => 'title'],
        ],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    return $type;
}

function dbStoreDeliveryToken(): string
{
    $user = User::factory()->create();
    $result = $user->createToken('db-delivery', ['delivery'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $result->plainTextToken;
}

// ── TagAwareCache low-level ────────────────────────────────────────────────────

it('TagAwareCache stores and retrieves on the non-taggable database store', function (): void {
    $cache = new TagAwareCache;
    $tags = ['magna.delivery', 'magna.delivery.type.dbc_article'];

    $cache->put('body-key', '{"data":[]}', 300, $tags);

    expect($cache->get('body-key', $tags))->toBe('{"data":[]}');
});

it('flushing a type tag invalidates only that type on the database store', function (): void {
    $cache = new TagAwareCache;
    $post = ['magna.delivery', 'magna.delivery.type.post'];
    $page = ['magna.delivery', 'magna.delivery.type.page'];

    $cache->put('kp', 'post', 300, $post);
    $cache->put('kg', 'page', 300, $page);

    $cache->flushTag('magna.delivery.type.post');

    expect($cache->get('kp', $post))->toBeNull();      // flushed
    expect($cache->get('kg', $page))->toBe('page');    // untouched
});

it('flushing the global tag invalidates every type on the database store', function (): void {
    $cache = new TagAwareCache;
    $post = ['magna.delivery', 'magna.delivery.type.post'];
    $page = ['magna.delivery', 'magna.delivery.type.page'];

    $cache->put('kp', 'post', 300, $post);
    $cache->put('kg', 'page', 300, $page);

    $cache->flushTag('magna.delivery');

    expect($cache->get('kp', $post))->toBeNull();
    expect($cache->get('kg', $page))->toBeNull();
});

// ── Services ───────────────────────────────────────────────────────────────────

it('ResponseCacheService put/get/invalidate never throws on the database store', function (): void {
    $rc = app(ResponseCacheService::class);

    $rc->put('magna.delivery.body.dbk', '{"data":[]}', 'post');
    expect($rc->get('magna.delivery.body.dbk', 'post'))->toBe('{"data":[]}');

    $rc->invalidateType('post');
    expect($rc->get('magna.delivery.body.dbk', 'post'))->toBeNull();

    // The untagged grace copy survives a type flush (stampede serving).
    expect($rc->getStale('magna.delivery.body.dbk'))->toBe('{"data":[]}');
});

it('ETagService store/check/invalidate never throws on the database store', function (): void {
    $etag = app(ETagService::class);
    $etag->store('magna.delivery.etag.dbk', '"abc"', 'post');

    $req = Request::create('/api/v1/content/post', 'GET', server: ['HTTP_IF_NONE_MATCH' => '"abc"']);
    expect($etag->check($req, 'magna.delivery.etag.dbk', 'post'))->toBe('"abc"');

    $etag->invalidateType('post');
    expect($etag->check($req, 'magna.delivery.etag.dbk', 'post'))->toBeNull();
});

// ── End-to-end HTTP (fresh-install default) ───────────────────────────────────

it('delivery LIST endpoint returns 200 (not 500) on the default database store', function (): void {
    dbStoreArticleType();
    Entry::type('dbc_article')->create([
        'title' => 'First', 'slug' => 'first',
        'status' => EntryStatus::Published, 'locale' => '', 'published_at' => now(),
    ]);
    $token = dbStoreDeliveryToken();

    $miss = $this->getJson('/api/v1/content/dbc_article', ['Authorization' => 'Bearer '.$token]);
    $miss->assertStatus(200)->assertJsonPath('data.0.slug', 'first');
    expect($miss->headers->get('X-Cache'))->toBe('MISS');

    // Second identical request must be served from the (database-store) body cache.
    $hit = $this->getJson('/api/v1/content/dbc_article', ['Authorization' => 'Bearer '.$token]);
    $hit->assertStatus(200);
    expect($hit->headers->get('X-Cache'))->toBe('HIT');
});

it('delivery SINGLE endpoint returns 200 (not 500) on the default database store', function (): void {
    dbStoreArticleType();
    $entry = Entry::type('dbc_article')->create([
        'title' => 'Solo', 'slug' => 'solo',
        'status' => EntryStatus::Published, 'locale' => '', 'published_at' => now(),
    ]);
    $token = dbStoreDeliveryToken();

    $this->getJson('/api/v1/content/dbc_article/'.$entry->id, ['Authorization' => 'Bearer '.$token])
        ->assertStatus(200)
        ->assertJsonPath('data.slug', 'solo');
});
