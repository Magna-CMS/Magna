<?php

declare(strict_types=1);

/**
 * The HTML page cache (§B1 v1): guest hits served from the DB-backed cache
 * with exact per-page purge on content edits and sitewide flush only where
 * blast radius genuinely is sitewide (slug renames, menu saves). Per-user
 * and parameterized responses never enter the shared cache.
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\Menus\MenuManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function cacheSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    return User::factory()->create();
}

function cachePublishPage(User $author, string $title, string $slug): Entry
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => $title, 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => $title]]],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('serves the second guest request from cache', function (): void {
    $author = cacheSetup();
    cachePublishPage($author, 'Cached page', 'cached-page');

    $this->get('/cached-page')->assertOk()->assertHeader('X-Magna-Cache', 'miss');

    // Prove the second response comes from storage: poison the cached body.
    DB::table('pages_cache')->update(['body' => 'FROM-THE-CACHE']);

    $this->get('/cached-page')
        ->assertOk()
        ->assertHeader('X-Magna-Cache', 'hit')
        ->assertSee('FROM-THE-CACHE');
});

it('purges exactly the edited page', function (): void {
    $author = cacheSetup();
    $page = cachePublishPage($author, 'Edit me', 'edit-me');
    cachePublishPage($author, 'Untouched', 'untouched');

    $this->get('/edit-me')->assertOk();
    $this->get('/untouched')->assertOk();
    expect(DB::table('pages_cache')->count())->toBe(2);

    app(EntryManager::class)->update($page, ['title' => 'Edited'], $author->id);

    $urls = DB::table('pages_cache')->pluck('url');
    expect($urls)->toHaveCount(1)
        ->and($urls->first())->toBe('/untouched');

    $this->get('/edit-me')->assertOk()->assertSee('Edited');
});

it('flushes the site on slug renames and menu saves', function (): void {
    $author = cacheSetup();
    $page = cachePublishPage($author, 'Renamer', 'renamer');
    cachePublishPage($author, 'Bystander', 'bystander');

    $this->get('/renamer')->assertOk();
    $this->get('/bystander')->assertOk();
    expect(DB::table('pages_cache')->count())->toBe(2);

    app(EntryManager::class)->update($page, ['slug' => 'renamed'], $author->id);
    expect(DB::table('pages_cache')->count())->toBe(0);

    $this->get('/bystander')->assertOk();
    expect(DB::table('pages_cache')->count())->toBe(1);

    $menus = app(MenuManager::class);
    $menus->syncItems($menus->create('primary', 'Primary'), [
        ['label' => 'X', 'type' => 'url', 'url' => '/x'],
    ]);
    expect(DB::table('pages_cache')->count())->toBe(0);
});

it('never caches authenticated, parameterized, or 404 responses', function (): void {
    $author = cacheSetup();
    cachePublishPage($author, 'Guest only', 'guest-only');

    $this->actingAs($author)->get('/guest-only')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');
    expect(DB::table('pages_cache')->count())->toBe(0);

    // Fresh guest context for the remaining checks.
    auth()->logout();

    $this->get('/guest-only?utm_source=x')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');
    expect(DB::table('pages_cache')->count())->toBe(0);

    $this->get('/definitely-not-here')->assertNotFound();
    expect(DB::table('pages_cache')->count())->toBe(0);
});

it('expires entries past their TTL and prunes them', function (): void {
    $author = cacheSetup();
    cachePublishPage($author, 'Expiring', 'expiring');

    $this->get('/expiring')->assertOk();
    DB::table('pages_cache')->update(['expires_at' => now()->subMinute()]);

    // Expired hit falls through to a fresh render (and re-caches).
    $this->get('/expiring')->assertOk()->assertHeader('X-Magna-Cache', 'miss');

    DB::table('pages_cache')->update(['expires_at' => now()->subMinute()]);
    expect(app(PageCache::class)->prune())->toBe(1)
        ->and(DB::table('pages_cache')->count())->toBe(0);
});

it('schedules the hourly cache prune when the plugin is enabled', function (): void {
    cacheSetup();

    $schedule = app(Schedule::class);
    $commands = array_map(
        fn (Event $event): string => $event->command ?? '',
        $schedule->events(),
    );

    $pruneEvents = array_filter($commands, fn (string $c): bool => str_contains($c, 'magna:pages:cache-prune'));
    expect($pruneEvents)->not->toBeEmpty();
});
