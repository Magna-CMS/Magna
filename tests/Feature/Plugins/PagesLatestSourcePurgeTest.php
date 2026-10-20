<?php

declare(strict_types=1);

/**
 * The built-in "Latest pages" source and the cache (Pages 0.5.0). A cached
 * page listing the latest pages used to keep listing yesterday's pages for
 * up to an hour: publishing a page purged only that page. The source now
 * tags what it renders `pages:listing`, and every page publish, update and
 * unpublish purges that tag — and nothing else that did not list pages.
 */

use Illuminate\Support\Facades\DB;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function latestPurgeSetup(): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    return User::factory()->create();
}

/** @param  array<string, mixed>  $block */
function latestPurgePage(User $author, string $title, string $slug, array $block): Entry
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => $title, 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-lps-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-lps-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-lps-'.$slug, 'settings' => [], ...$block]],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

/** @return list<string> */
function latestPurgeCachedUrls(): array
{
    return DB::table('pages_cache')->orderBy('url')->pluck('url')->all();
}

it('tags a page that lists the latest pages', function (): void {
    $author = latestPurgeSetup();
    latestPurgePage($author, 'Index', 'site-index', ['block' => 'loop', 'data' => ['source' => 'pages.latest', 'limit' => 10]]);

    $this->get('/site-index')->assertOk()->assertHeader('X-Magna-Cache', 'miss');

    $id = DB::table('pages_cache')->where('url', '/site-index')->value('id');
    expect(DB::table('pages_cache_keys')->where('cache_id', $id)->pluck('surrogate_key')->all())
        ->toContain('pages:listing');
});

it('purges the listing when a page is published, updated or unpublished', function (): void {
    $author = latestPurgeSetup();
    $manager = app(EntryManager::class);
    latestPurgePage($author, 'Index', 'site-index', ['block' => 'loop', 'data' => ['source' => 'pages.latest', 'limit' => 10]]);
    latestPurgePage($author, 'Bystander', 'bystander', ['block' => 'heading', 'data' => ['text' => 'Unrelated']]);

    $warm = function (): void {
        $this->get('/site-index')->assertOk();
        $this->get('/bystander')->assertOk();
        expect(latestPurgeCachedUrls())->toBe(['/bystander', '/site-index']);
    };

    $warm();
    $news = latestPurgePage($author, 'Fresh news', 'fresh-news', ['block' => 'heading', 'data' => ['text' => 'News']]);
    expect(latestPurgeCachedUrls())->toBe(['/bystander']);
    $this->get('/site-index')->assertOk()->assertSee('Fresh news');

    $warm();
    $news = $manager->update($news, ['title' => 'Fresher news'], $author->id);
    expect(latestPurgeCachedUrls())->toBe(['/bystander']);

    $warm();
    $manager->unpublish($news, $author->id);
    expect(latestPurgeCachedUrls())->toBe(['/bystander']);
    $this->get('/site-index')->assertOk()->assertDontSee('href="/fresh-news"', false);
});
