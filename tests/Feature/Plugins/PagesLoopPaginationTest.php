<?php

declare(strict_types=1);

/**
 * The Loop block's `pagination` field (Pages 0.5.0): set to `pages` over a
 * PaginatedDataSource it fetches the requested page and renders core's
 * pagination partial; left at the default, or over a source that cannot
 * paginate, it stays the plain clamped list it always was. A source that
 * DeclaresCacheability tags the cached page and caps its life.
 */

use Illuminate\Support\Facades\DB;
use Magna\Blocks\DataSources\DataSourcePage;
use Magna\Blocks\DataSources\DataSourceRegistry;
use Magna\Blocks\DataSources\DeclaresCacheability;
use Magna\Blocks\DataSources\PaginatedDataSource;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Frontend\Events\PublicContentChanged;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

/** Ten feed items; records how it was asked so tests can see the call. */
final class FixtureTaggedFeedSource implements DeclaresCacheability, PaginatedDataSource
{
    /** @var list<string> */
    public static array $calls = [];

    /** @var array<string, mixed> */
    public static array $lastConfig = [];

    public static ?DateTimeInterface $nextChange = null;

    public function handle(): string
    {
        return 'fixture.tagged-feed';
    }

    public function label(): string
    {
        return 'Tagged feed';
    }

    public function fetch(array $config): array
    {
        self::$calls[] = 'fetch';
        self::$lastConfig = $config;

        return $this->items(1, 50);
    }

    public function fetchPage(array $config, int $page, int $perPage): DataSourcePage
    {
        self::$calls[] = "fetchPage:{$page}:{$perPage}";
        self::$lastConfig = $config;

        return new DataSourcePage(array_slice($this->items(1, 10), ($page - 1) * $perPage, $perPage), 10);
    }

    public function cacheTags(array $config): array
    {
        return ['fixture:feed'];
    }

    public function nextChangeAt(array $config): ?DateTimeInterface
    {
        return self::$nextChange;
    }

    /** @return list<array<string, string>> */
    private function items(int $from, int $to): array
    {
        return array_map(fn (int $i): array => ['title' => "Feed item {$i}", 'url' => '/feed/'.$i], range($from, $to));
    }
}

function loopPagingSetup(): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
    app(DataSourceRegistry::class)->register(new FixtureTaggedFeedSource);
    FixtureTaggedFeedSource::$calls = [];
    FixtureTaggedFeedSource::$lastConfig = [];
    FixtureTaggedFeedSource::$nextChange = null;

    return User::factory()->create();
}

/** @param  array<string, mixed>  $loopData */
function loopPagingPage(User $author, string $slug, array $loopData): Entry
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Feed '.$slug, 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-lpg-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-lpg-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-lpg-'.$slug, 'block' => 'loop', 'settings' => [], 'data' => $loopData]],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('pages through a paginated source and renders the shared navigation', function (): void {
    $author = loopPagingSetup();
    loopPagingPage($author, 'feed', ['source' => 'fixture.tagged-feed', 'limit' => 4, 'pagination' => 'pages']);

    $first = (string) $this->get('/feed')->assertOk()->getContent();
    expect($first)->toContain('Feed item 4')
        ->and($first)->not->toContain('Feed item 5')
        ->and($first)->toContain('class="magna-pagination"')
        ->and($first)->toContain('Page 1 of 3')
        ->and($first)->toContain('href="/feed?page=2" rel="next"')
        ->and($first)->not->toContain('rel="prev"');

    $last = (string) $this->get('/feed?page=3')->assertOk()->getContent();
    expect($last)->toContain('Feed item 9')
        ->and($last)->toContain('Feed item 10')
        ->and($last)->not->toContain('href="/feed/8"')
        ->and($last)->toContain('href="/feed?page=2" rel="prev"')
        ->and($last)->not->toContain('rel="next"');

    expect(FixtureTaggedFeedSource::$calls)->toBe(['fetchPage:1:4', 'fetchPage:3:4']);
});

it('keeps a loop without the pagination setting on fetch(), unpaged', function (): void {
    $author = loopPagingSetup();
    loopPagingPage($author, 'feed-plain', ['source' => 'fixture.tagged-feed', 'limit' => 3]);

    $html = (string) $this->get('/feed-plain')->assertOk()->getContent();

    expect(substr_count($html, 'class="magna-loop__item"'))->toBe(3)
        ->and($html)->not->toContain('magna-pagination')
        ->and(FixtureTaggedFeedSource::$calls)->toBe(['fetch']);
});

it('ignores the pagination setting for a source that cannot paginate', function (): void {
    $author = loopPagingSetup();
    loopPagingPage($author, 'latest-paged', ['source' => 'pages.latest', 'limit' => 5, 'pagination' => 'pages']);

    $html = (string) $this->get('/latest-paged')->assertOk()->getContent();

    expect($html)->toContain('Feed latest-paged')
        ->and($html)->not->toContain('magna-pagination');
});

it('hands the source the limit and the request locale', function (): void {
    $author = loopPagingSetup();
    loopPagingPage($author, 'feed-config', ['source' => 'fixture.tagged-feed', 'limit' => 2, 'pagination' => 'pages']);

    $this->get('/feed-config')->assertOk();

    expect(FixtureTaggedFeedSource::$lastConfig)->toMatchArray(['limit' => 2, 'locale' => 'en']);
});

it('tags the cached page with the source tags and expires it at the next change', function (): void {
    $author = loopPagingSetup();
    $page = loopPagingPage($author, 'feed-tagged', ['source' => 'fixture.tagged-feed', 'limit' => 4, 'pagination' => 'pages']);
    FixtureTaggedFeedSource::$nextChange = now()->addMinutes(10);

    $this->get('/feed-tagged')->assertOk()->assertHeader('X-Magna-Cache', 'miss');

    $row = DB::table('pages_cache')->where('url', '/feed-tagged')->first();
    expect($row)->not->toBeNull();

    $keys = DB::table('pages_cache_keys')->where('cache_id', $row->id)->pluck('surrogate_key')->sort()->values()->all();
    expect($keys)->toBe(['fixture:feed', 'page:'.$page->getKey(), 'site:pages']);

    // Ten minutes, not the hour-long default TTL.
    expect(now()->diffInSeconds($row->expires_at))->toBeLessThanOrEqual(600);

    PublicContentChanged::dispatch(['fixture:feed'], false, 'test');
    expect(DB::table('pages_cache')->count())->toBe(0);
});
