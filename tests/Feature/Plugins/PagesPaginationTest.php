<?php

declare(strict_types=1);

/**
 * Paginated public pages and the page cache (Pages 0.5.0): `?page=N` is the
 * one query string a cached response may carry, and only when something on
 * the page actually paginated — otherwise it is a duplicate of page 1 and
 * stays out of the cache. Any other query string bypasses. A page past the
 * end is the themed 404, never stored. The collection archive links its
 * pages through core's shared pagination partial.
 */

use Illuminate\Support\Facades\DB;
use Magna\Blocks\DataSources\DataSourcePage;
use Magna\Blocks\DataSources\DataSourceRegistry;
use Magna\Blocks\DataSources\PaginatedDataSource;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\EntryStatus;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Pages\PagesSettings;
use Magna\Plugins\PluginManager;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

/** Twelve notes, served five to a page. */
final class FixturePagedNotesSource implements PaginatedDataSource
{
    public function handle(): string
    {
        return 'fixture.paged-notes';
    }

    public function label(): string
    {
        return 'Paged notes';
    }

    public function fetch(array $config): array
    {
        return $this->fetchPage($config, 1, 5)->items;
    }

    public function fetchPage(array $config, int $page, int $perPage): DataSourcePage
    {
        $slice = array_slice(range(1, 12), ($page - 1) * $perPage, $perPage);

        return new DataSourcePage(
            array_map(fn (int $i): array => ['title' => "Note {$i}", 'url' => '/notes/'.$i], $slice),
            12,
        );
    }
}

function pagedSetup(): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
    app(DataSourceRegistry::class)->register(new FixturePagedNotesSource);

    return User::factory()->create();
}

/** @param  array<string, mixed>  $blockData */
function pagedPublish(User $author, string $slug, string $block, array $blockData): Entry
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Paged '.$slug, 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-pg-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-pg-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-pg-'.$slug, 'block' => $block, 'settings' => [], 'data' => $blockData]],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

function pagedListing(User $author, string $slug): Entry
{
    return pagedPublish($author, $slug, 'loop', [
        'heading' => 'Notes', 'source' => 'fixture.paged-notes', 'limit' => 5, 'pagination' => 'pages',
    ]);
}

it('caches each page of a paginated listing under its own URL', function (): void {
    $author = pagedSetup();
    pagedListing($author, 'notes-list');

    $this->get('/notes-list')->assertOk()->assertHeader('X-Magna-Cache', 'miss');

    $second = $this->get('/notes-list?page=2')->assertOk()->assertHeader('X-Magna-Cache', 'miss')->getContent();
    expect($second)->toContain('Note 6')
        ->and($second)->not->toContain('href="/notes/1"');

    expect(DB::table('pages_cache')->orderBy('url')->pluck('url')->all())
        ->toBe(['/notes-list', '/notes-list?page=2']);

    DB::table('pages_cache')->where('url', '/notes-list?page=2')->update(['body' => 'SECOND-PAGE-FROM-CACHE']);

    $this->get('/notes-list?page=2')
        ->assertOk()
        ->assertHeader('X-Magna-Cache', 'hit')
        ->assertSee('SECOND-PAGE-FROM-CACHE');
});

it('never stores ?page=N for a page that does not paginate', function (): void {
    $author = pagedSetup();
    pagedPublish($author, 'plain-notes', 'heading', ['text' => 'Nothing to page through']);

    $this->get('/plain-notes?page=2')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');
    expect(DB::table('pages_cache')->count())->toBe(0);

    // Page 1 at the bare path is still an ordinary cacheable page.
    $this->get('/plain-notes')->assertOk()->assertHeader('X-Magna-Cache', 'miss');
    expect(DB::table('pages_cache')->pluck('url')->all())->toBe(['/plain-notes']);
});

it('bypasses the cache for every other query string', function (): void {
    $author = pagedSetup();
    pagedListing($author, 'query-notes');

    foreach (['page=1', 'page=02', 'page=abc', 'page=2&utm_source=x', 'utm_source=x'] as $query) {
        $this->get('/query-notes?'.$query)->assertOk()->assertHeader('X-Magna-Cache', 'bypass');
    }

    expect(DB::table('pages_cache')->count())->toBe(0);
});

it('answers a page past the end with the themed 404 and never stores it', function (): void {
    $author = pagedSetup();
    pagedListing($author, 'short-notes');

    $this->get('/short-notes?page=3')->assertOk()->assertSee('Note 11');

    $this->get('/short-notes?page=4')->assertNotFound()->assertSee('Page not found');
    $this->get('/short-notes?page=101')->assertNotFound();

    expect(DB::table('pages_cache')->pluck('url')->all())->toBe(['/short-notes?page=3']);
});

it('links a collection archive across pages through the shared partial', function (): void {
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    $schemaRegistry = app(SchemaRegistry::class);
    $schemaRegistry->register(ContentType::fromArray([
        'handle' => 'paged_article',
        'displayName' => 'Paged articles',
        'localizable' => false,
        'draftable' => false,
        'publiclyRenderable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug'],
        ],
    ], app(FieldTypeRegistry::class)));
    app(SchemaSyncer::class)->syncAll($schemaRegistry, allowDestructive: true);

    $settings = PagesSettings::get();
    $settings->collection_mounts = [['type' => 'paged_article', 'prefix' => 'articles']];
    app(SettingsRepository::class)->persist($settings);

    $author = User::factory()->create();
    $manager = app(EntryManager::class);
    foreach (range(1, 25) as $i) {
        $manager->create('paged_article', ['title' => "Article {$i}", 'slug' => 'article-'.$i], $author->id)
            ->forceFill(['status' => EntryStatus::Published->value, 'published_at' => now()->subMinutes($i)])
            ->save();
    }

    $first = (string) $this->get('/articles')->assertOk()->getContent();
    expect($first)->toContain('class="magna-pagination"')
        ->and($first)->toContain('href="/articles?page=2" rel="next"')
        ->and($first)->not->toContain('rel="prev"');

    $second = (string) $this->get('/articles?page=2')->assertOk()->getContent();
    expect($second)->toContain('Article 25')
        ->and($second)->toContain('href="/articles" rel="prev"')
        ->and($second)->not->toContain('rel="next"');

    $this->get('/articles?page=3')->assertNotFound();
});
