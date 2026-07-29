<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\Events\EntryDeleted;
use Magna\Content\Events\EntryPublished;
use Magna\Content\Events\EntryUnpublished;
use Magna\Content\Events\EntryUpdated;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Delivery\EdgeCache\Contracts\PurgesEdgeCache;
use Magna\Delivery\EdgeCache\Drivers\NullEdgeCacheDriver;
use Magna\Delivery\EdgeCache\EdgeCacheDispatcher;
use Magna\Delivery\EdgeCache\Jobs\PurgeEdgeCacheJob;
use Magna\Delivery\ETagService;
use Magna\Delivery\Listeners\DeliveryCacheInvalidator;
use Magna\Delivery\ResponseCacheService;
use Magna\Delivery\SurrogateKeyCollector;
use Magna\Media\Events\MediaDeleted;
use Magna\Media\Media;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function setupCacheType(string $handle = 'cache_article'): ContentType
{
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => $handle,
        'displayName' => 'Cache Article',
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

function cacheTestEntry(string $handle = 'cache_article'): Entry
{
    return Entry::type($handle)->create([
        'title' => 'Cache test',
        'slug' => 'cache-test-'.uniqid(),
        'status' => EntryStatus::Published,
        'locale' => '',
        'published_at' => now(),
    ]);
}

function cacheDeliveryToken(): string
{
    $user = User::factory()->create();
    $result = $user->createToken('cache-test', ['delivery'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $result->plainTextToken;
}

// ── SurrogateKeyCollector ─────────────────────────────────────────────────────

it('collector produces correct CDN header value', function (): void {
    $collector = new SurrogateKeyCollector;
    $collector->addType('article');
    $collector->addEntry('01ABC');
    $collector->addMedia('01DEF');

    expect($collector->headerValue())->toBe('type:article entry:01ABC media:01DEF');
});

it('collector cacheTagKeys includes the magna.delivery root tag', function (): void {
    $collector = new SurrogateKeyCollector;
    $collector->addType('article');
    $collector->addEntry('01ABC');

    $tags = $collector->cacheTagKeys();
    expect($tags)->toContain('magna.delivery')
        ->toContain('magna.delivery.type.article')
        ->toContain('magna.delivery.entry.01ABC');
});

// ── NullEdgeCacheDriver ───────────────────────────────────────────────────────

it('null edge cache driver is the default binding', function (): void {
    $driver = app(PurgesEdgeCache::class);

    expect($driver)->toBeInstanceOf(NullEdgeCacheDriver::class);
});

it('null driver purge is a no-op (no exception, no HTTP call)', function (): void {
    $driver = new NullEdgeCacheDriver;

    expect(fn () => $driver->purge(['type:article', 'entry:01ABC']))->not->toThrow(Throwable::class);
});

// ── PurgeEdgeCacheJob ─────────────────────────────────────────────────────────

it('PurgeEdgeCacheJob dispatches the configured driver', function (): void {
    Queue::fake();
    $dispatcher = app(EdgeCacheDispatcher::class);

    $dispatcher->dispatch(['type:article', 'media:01DEF']);

    Queue::assertPushed(PurgeEdgeCacheJob::class, function ($job) {
        // Inspect job's serialised keys via reflection
        $ref = new ReflectionClass($job);
        $prop = $ref->getProperty('keys');
        $prop->setAccessible(true);

        return $prop->getValue($job) === ['type:article', 'media:01DEF'];
    });
});

// ── DeliveryCacheInvalidator ──────────────────────────────────────────────────

it('EntryPublished flushes ETag cache for the type and queues edge purge', function (): void {
    Queue::fake();
    setupCacheType();
    $entry = cacheTestEntry();

    // Prime the ETag cache
    $etag = app(ETagService::class);
    $etag->store('magna.delivery.etag.somekey', '"abc"', 'cache_article');

    // Simulate publishing
    Event::dispatch(new EntryPublished($entry, null));

    // ETag for the type should be flushed (store should return null now)
    $cached = Cache::tags(['magna.delivery', 'magna.delivery.type.cache_article'])->get('magna.delivery.etag.somekey');
    expect($cached)->toBeNull();

    // Edge purge job must have been queued
    Queue::assertPushed(PurgeEdgeCacheJob::class);
});

it('EntryUpdated flushes the body cache and queues an edge purge', function (): void {
    Queue::fake();
    setupCacheType();
    $entry = cacheTestEntry();

    $rc = app(ResponseCacheService::class);
    $rc->put('magna.delivery.body.somekey', '{"data":{}}', 'cache_article');

    // Ensure stored
    expect($rc->get('magna.delivery.body.somekey', 'cache_article'))->toBe('{"data":{}}');

    Event::dispatch(new EntryUpdated($entry, null));

    // Body cache should now be flushed
    expect($rc->get('magna.delivery.body.somekey', 'cache_article'))->toBeNull();
    Queue::assertPushed(PurgeEdgeCacheJob::class);
});

it('EntryDeleted and EntryUnpublished both flush the type cache', function (): void {
    Queue::fake();
    setupCacheType();
    $entry = cacheTestEntry();

    $etag = app(ETagService::class);
    $etag->store('magna.delivery.etag.k1', '"del"', 'cache_article');

    Event::dispatch(new EntryDeleted($entry, null));
    $after = Cache::tags(['magna.delivery', 'magna.delivery.type.cache_article'])->get('magna.delivery.etag.k1');
    expect($after)->toBeNull();

    // Re-prime for EntryUnpublished
    $etag->store('magna.delivery.etag.k2', '"unpub"', 'cache_article');
    Event::dispatch(new EntryUnpublished($entry, null));
    $after2 = Cache::tags(['magna.delivery', 'magna.delivery.type.cache_article'])->get('magna.delivery.etag.k2');
    expect($after2)->toBeNull();
});

it('MediaDeleted flushes the entire delivery cache and queues an edge purge', function (): void {
    Queue::fake();
    setupCacheType();

    $media = Media::forceCreate([
        'id' => Str::ulid(),
        'filename' => 'test.jpg',
        'original_filename' => 'test.jpg',
        'disk' => 'local',
        'path' => 'test.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
    ]);

    $etag = app(ETagService::class);
    $etag->store('magna.delivery.etag.mediakey', '"media"', 'cache_article');

    Event::dispatch(new MediaDeleted($media));

    // Entire delivery cache flushed (all tags under magna.delivery)
    $val = Cache::tags(['magna.delivery', 'magna.delivery.type.cache_article'])->get('magna.delivery.etag.mediakey');
    expect($val)->toBeNull();
    Queue::assertPushed(PurgeEdgeCacheJob::class);
});

// ── Stampede protection ───────────────────────────────────────────────────────

it('tryLock returns true for the first caller and false for subsequent callers', function (): void {
    $rc = app(ResponseCacheService::class);
    $key = 'magna.delivery.body.'.uniqid();

    expect($rc->tryLock($key))->toBeTrue();
    // Second call on same key loses the lock.
    expect($rc->tryLock($key))->toBeFalse();

    $rc->releaseLock($key);
});

it('stale grace copy is served after put() even after tagged cache is flushed', function (): void {
    $rc = app(ResponseCacheService::class);
    $cacheKey = 'magna.delivery.body.staletest';

    $rc->put($cacheKey, '{"stale":true}', 'stale_test');

    // Invalidate the type (simulating a content change)
    $rc->invalidateType('stale_test');

    // Tagged get should now return null
    expect($rc->get($cacheKey, 'stale_test'))->toBeNull();

    // Stale grace copy should still be available
    expect($rc->getStale($cacheKey))->toBe('{"stale":true}');
});

// ── Delivery API — Surrogate-Key header ───────────────────────────────────────

it('delivery list response carries a Surrogate-Key header', function (): void {
    setupCacheType('inv_article');
    cacheTestEntry('inv_article');
    $token = cacheDeliveryToken();

    $response = $this->getJson('/api/v1/content/inv_article', ['Authorization' => 'Bearer '.$token]);
    $response->assertOk();

    expect($response->headers->get('Surrogate-Key'))->toContain('type:inv_article');
});

it('publish purges ONLY the affected type tag and not unrelated types', function (): void {
    Queue::fake();
    setupCacheType('type_a');
    setupCacheType('type_b');
    $entryA = cacheTestEntry('type_a');

    $etag = app(ETagService::class);
    $etag->store('magna.delivery.etag.type_a_key', '"a"', 'type_a');
    $etag->store('magna.delivery.etag.type_b_key', '"b"', 'type_b');

    Event::dispatch(new EntryPublished($entryA, null));

    // type_a ETag flushed
    $a = Cache::tags(['magna.delivery', 'magna.delivery.type.type_a'])->get('magna.delivery.etag.type_a_key');
    expect($a)->toBeNull();

    // type_b ETag NOT flushed (different type)
    $b = Cache::tags(['magna.delivery', 'magna.delivery.type.type_b'])->get('magna.delivery.etag.type_b_key');
    expect($b)->toBe('"b"');
});
