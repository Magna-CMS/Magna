<?php

declare(strict_types=1);

/**
 * Plugin frontend pages in the page cache (Pages 0.5.0). A data provider's
 * FrontendPageResult opts its response in with `cacheable`; only a guest's
 * response to a page with no auth or permission requirement is stored,
 * tagged with the result's own tags plus `frontend:{name}` and `site:pages`,
 * and never past the result's `expiresAt`. PublicContentChanged purges by
 * tag, or everything. Anything rendered on the page may veto the store.
 */

use Illuminate\Support\Facades\DB;
use Magna\Frontend\CacheCollector;
use Magna\Frontend\Events\PublicContentChanged;
use Magna\Frontend\FrontendPage;
use Magna\Frontend\FrontendPageDataProvider;
use Magna\Frontend\FrontendPageRegistry;
use Magna\Frontend\FrontendPageResult;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

/** Cacheable stories, except "live" (opts out) and "vetoed" (vetoes mid-render). */
final class FixtureCacheableStoryProvider implements FrontendPageDataProvider
{
    public static int $calls = 0;

    public static ?DateTimeInterface $expiresAt = null;

    public function resolve(array $params): ?FrontendPageResult
    {
        self::$calls++;
        $slug = $params['slug'] ?? '';

        if ($slug === 'vetoed') {
            app(CacheCollector::class)->veto('shows the visitor something of their own');
        }

        return new FrontendPageResult(
            data: ['postTitle' => 'Story '.$slug, 'body' => 'Story body'],
            title: 'Story '.$slug,
            cacheable: $slug !== 'live',
            cacheTags: ['story:'.$slug, 'stories'],
            expiresAt: self::$expiresAt,
        );
    }
}

function storyCacheSetup(): void
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
    FixtureCacheableStoryProvider::$calls = 0;
    FixtureCacheableStoryProvider::$expiresAt = null;

    view()->addNamespace('fpstub', dirname(__DIR__, 2).'/stubs/frontend');

    $registry = app(FrontendPageRegistry::class);
    $registry->register(new FrontendPage(
        path: 'stories/{slug}', name: 'stories.show', title: 'Story', view: 'fpstub::post',
        controller: FixtureCacheableStoryProvider::class,
    ));
    $registry->register(new FrontendPage(
        path: 'member-stories/{slug}', name: 'stories.members', title: 'Member story', view: 'fpstub::post',
        requiresAuth: true, controller: FixtureCacheableStoryProvider::class,
    ));
}

/** @return list<string> */
function storyCacheKeys(string $url): array
{
    $row = DB::table('pages_cache')->where('url', $url)->first();

    return $row === null ? [] : DB::table('pages_cache_keys')
        ->where('cache_id', $row->id)->pluck('surrogate_key')->sort()->values()->all();
}

it('stores a cacheable result for guests and serves the next request from the cache', function (): void {
    storyCacheSetup();

    $this->get('/stories/first')->assertOk()->assertHeader('X-Magna-Cache', 'miss')->assertSee('Story first');
    $this->get('/stories/first')->assertOk()->assertHeader('X-Magna-Cache', 'hit')->assertSee('Story first');

    expect(FixtureCacheableStoryProvider::$calls)->toBe(1)
        ->and(storyCacheKeys('/stories/first'))->toBe(['frontend:stories.show', 'site:pages', 'stories', 'story:first']);
});

it('leaves a result that does not opt in uncached', function (): void {
    storyCacheSetup();

    $this->get('/stories/live')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');

    expect(DB::table('pages_cache')->count())->toBe(0);
});

it('never caches a signed-in visitor or a page behind an auth requirement', function (): void {
    storyCacheSetup();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/stories/first')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');
    $this->actingAs($user)->get('/member-stories/first')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');

    expect(DB::table('pages_cache')->count())->toBe(0);
});

it('caps the cached copy at the result expiry', function (): void {
    storyCacheSetup();
    FixtureCacheableStoryProvider::$expiresAt = now()->addMinutes(5);

    $this->get('/stories/soon')->assertOk()->assertHeader('X-Magna-Cache', 'miss');

    $expires = DB::table('pages_cache')->where('url', '/stories/soon')->value('expires_at');
    expect(now()->diffInSeconds($expires))->toBeLessThanOrEqual(300);
});

it('lets anything rendered on the page veto the cache', function (): void {
    storyCacheSetup();

    $this->get('/stories/vetoed')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');

    expect(DB::table('pages_cache')->count())->toBe(0);
});

it('purges by tag, or everything, when public content changes', function (): void {
    storyCacheSetup();

    $this->get('/stories/first')->assertOk();
    $this->get('/stories/second')->assertOk();

    PublicContentChanged::dispatch(['story:first'], false, 'test');
    expect(DB::table('pages_cache')->pluck('url')->all())->toBe(['/stories/second']);

    PublicContentChanged::dispatch([], true, 'test');
    expect(DB::table('pages_cache')->count())->toBe(0);
});
