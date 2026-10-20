<?php

declare(strict_types=1);

/**
 * Redirects for URLs another plugin moved (Pages 0.5.0). A plugin that
 * renames a public URL — a post slug, a permalink pattern — dispatches
 * core's PublicUrlsMoved; Pages records each move in its redirect table,
 * locale-less because the paths are full site-relative URLs, with the same
 * chain-collapse and loop-guard rules a page rename gets.
 */

use Illuminate\Support\Facades\DB;
use Magna\Frontend\Events\PublicUrlsMoved;
use Magna\Frontend\UrlMove;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\Routing\PageRedirect;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

function movedUrlsSetup(): void
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
}

/** @param  list<UrlMove>  $moves */
function movedUrls(array $moves): void
{
    PublicUrlsMoved::dispatch($moves, 'test');
}

it('records a 301 for every URL another plugin moved', function (): void {
    movedUrlsSetup();

    movedUrls([
        new UrlMove('/journal/old-post', '/journal/new-post'),
        new UrlMove('/journal/same', '/journal/same'),
    ]);

    $redirect = PageRedirect::query()->where('source_path', 'journal/old-post')->firstOrFail();
    expect($redirect->target_path)->toBe('journal/new-post')
        ->and($redirect->locale)->toBe('')
        ->and($redirect->status)->toBe(301)
        // A move onto itself records nothing — it would be a loop.
        ->and(PageRedirect::query()->where('source_path', 'journal/same')->exists())->toBeFalse();

    $this->get('/journal/old-post')
        ->assertStatus(301)
        ->assertHeader('Location', url('/journal/new-post'));
});

it('collapses chains and refuses loops across moves', function (): void {
    movedUrlsSetup();

    movedUrls([new UrlMove('/journal/a', '/journal/b')]);
    movedUrls([new UrlMove('/journal/b', '/journal/c')]);

    expect(PageRedirect::query()->where('source_path', 'journal/a')->value('target_path'))->toBe('journal/c');

    movedUrls([new UrlMove('/journal/c', '/journal/a')]);

    expect(PageRedirect::query()->where('source_path', 'journal/a')->exists())->toBeFalse();
    $this->get('/journal/c')->assertStatus(301)->assertHeader('Location', url('/journal/a'));
});

it('keeps the status a move asks for', function (): void {
    movedUrlsSetup();

    movedUrls([new UrlMove('/journal/for-now', '/journal/elsewhere', 302)]);

    $this->get('/journal/for-now')->assertStatus(302)->assertHeader('Location', url('/journal/elsewhere'));
});

it('drops the cached copy still standing at the old address', function (): void {
    movedUrlsSetup();
    app(PageCache::class)->put('/journal/cached-post', 'STALE-OLD-ADDRESS', ['blog:post:1']);

    movedUrls([new UrlMove('/journal/cached-post', '/journal/renamed-post')]);

    expect(DB::table('pages_cache')->count())->toBe(0);
    $this->get('/journal/cached-post')->assertStatus(301);
});
