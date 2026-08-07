<?php

declare(strict_types=1);

/**
 * The demo seeder (magna:pages:demo): one command produces the fully wired
 * starter site — published pages, primary menu, home setting — and refuses
 * to stomp existing content without --force.
 */

use Illuminate\Support\Facades\DB;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\PagesSettings;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function demoSetup(): void
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
}

it('seeds the demo site end to end', function (): void {
    demoSetup();

    $this->artisan('magna:pages:demo')->assertSuccessful();

    expect(Entry::type('page')->where('status', 'published')->count())->toBe(3)
        ->and(PagesSettings::get()->home_page_id)->not->toBeNull();

    // The seeded site actually serves: FAQ page with menu and content.
    $this->get('/faq')
        ->assertOk()
        ->assertSee('Good questions')
        ->assertSee('Do you ship whole bean?')
        ->assertSee('href="/about"', false); // primary menu (header or nav)

    // Sanitized richtext survived the system-context save.
    $this->get('/about')->assertOk()->assertSee('secondhand drum roaster');
});

it('refuses to seed over existing pages without --force', function (): void {
    demoSetup();
    $author = User::factory()->create();

    app(EntryManager::class)->create('page', [
        'title' => 'Existing', 'slug' => 'existing', 'blocks_data' => [],
    ], $author->id);

    $this->artisan('magna:pages:demo')->assertFailed();

    expect(Entry::type('page')->count())->toBe(1);

    $this->artisan('magna:pages:demo', ['--force' => true])->assertSuccessful();
    expect(Entry::type('page')->count())->toBe(4);
});

it('prunes expired cache entries via the command', function (): void {
    demoSetup();

    $this->artisan('magna:pages:demo')->assertSuccessful();
    $this->get('/faq')->assertOk(); // populate cache

    DB::table('pages_cache')->update(['expires_at' => now()->subMinute()]);

    $this->artisan('magna:pages:cache-prune')
        ->expectsOutputToContain('Pruned 1')
        ->assertSuccessful();

    expect(app(PageCache::class)->prune())->toBe(0);
});
