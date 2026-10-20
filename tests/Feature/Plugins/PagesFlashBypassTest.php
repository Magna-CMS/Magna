<?php

declare(strict_types=1);

/**
 * Flash data and the page cache (Pages 0.5.0). A form that redirects back
 * with a "sent" message or validation errors flashes them for exactly the
 * next request — the one that renders the page. Served from the cache, the
 * visitor never sees their message; stored, every later guest would see it.
 * So a request carrying flash data neither reads nor writes the cache.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function flashBypassPage(): void
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    $author = User::factory()->create();
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Contact', 'slug' => 'contact-us',
        'blocks_data' => [[
            'id' => 'sec-flash', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-flash', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-flash', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Write to us']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);
}

it('never serves a cached copy to a visitor carrying flash data', function (): void {
    flashBypassPage();

    $this->get('/contact-us')->assertOk()->assertHeader('X-Magna-Cache', 'miss');
    DB::table('pages_cache')->update(['body' => 'STALE-CACHED-COPY']);

    $this->withSession(['status' => 'Message sent', '_flash' => ['old' => ['status'], 'new' => []]])
        ->get('/contact-us')
        ->assertOk()
        ->assertHeader('X-Magna-Cache', 'bypass')
        ->assertSee('Write to us')
        ->assertDontSee('STALE-CACHED-COPY');
});

it('never stores a response rendered with flashed validation errors', function (): void {
    flashBypassPage();

    $errors = (new ViewErrorBag)->put('default', new MessageBag(['email' => ['The email field is required.']]));

    $this->withSession(['errors' => $errors, '_flash' => ['old' => ['errors'], 'new' => []]])
        ->get('/contact-us')
        ->assertOk()
        ->assertHeader('X-Magna-Cache', 'bypass');

    expect(DB::table('pages_cache')->count())->toBe(0);

    // The flash is spent; the next plain visit caches as usual.
    $this->get('/contact-us')->assertOk()->assertHeader('X-Magna-Cache', 'miss');
    expect(DB::table('pages_cache')->count())->toBe(1);
});
