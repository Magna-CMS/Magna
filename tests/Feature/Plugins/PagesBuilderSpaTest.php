<?php

declare(strict_types=1);

/**
 * Serving the builder SPA (docs/magna-pages/01-ARCHITECTURE.md §4).
 *
 * The bundle is committed so an install needs no Node; the shell is rendered
 * per page so the app knows its own identity without a round trip. Both the
 * shell and its assets are behind the same permission as the API they drive.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Filament\Pages\PagesIndexPage;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function spaUser(string ...$permissions): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant(...($permissions === [] ? ['pages.content'] : $permissions));
    $user->assignRole($role);

    return $user;
}

function spaPage(User $author): Entry
{
    return app(EntryManager::class)->create('page', [
        'title' => 'Builder target',
        'slug' => 'builder-target',
        'blocks_data' => [],
    ], $author->id);
}

it('stamps the page id and a CSRF token into the shell', function (): void {
    $author = spaUser();
    $page = spaPage($author);

    $html = $this->actingAs($author)
        ->get(url('/pages-builder/edit/'.$page->getKey()))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-page="'.$page->getKey().'"')
        ->and($html)->toContain('name="csrf-token"');
});

it('never lets the shell be cached', function (): void {
    $author = spaUser();
    $page = spaPage($author);

    $header = $this->actingAs($author)
        ->get(url('/pages-builder/edit/'.$page->getKey()))
        ->headers->get('Cache-Control');

    expect($header)->toContain('no-store');
});

it('serves hashed bundle assets immutably', function (): void {
    $author = spaUser();

    $bundle = glob(dirname(__DIR__, 3).'/plugins-dev/magna/pages/public/builder/assets/*.js') ?: [];
    if ($bundle === []) {
        $this->markTestSkipped('The builder bundle has not been built.');
    }

    $name = basename((string) $bundle[0]);

    $response = $this->actingAs($author)
        ->get(url('/pages-builder/app/assets/'.$name))
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/javascript')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable');
});

it('refuses to serve anything outside the bundle directory', function (): void {
    $author = spaUser();

    $this->actingAs($author)
        ->get(url('/pages-builder/app/').'/../../../composer.json')
        ->assertNotFound();
});

it('refuses the builder to a user without the pages permission', function (): void {
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $author = spaUser();
    $page = spaPage($author);

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(url('/pages-builder/edit/'.$page->getKey()))
        ->assertForbidden();
});

it('serves the canvas bridge as javascript', function (): void {
    $author = spaUser();

    $this->actingAs($author)
        ->get(url('/pages-builder/bridge.js'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
        ->assertSee('data-magna-node', escape: false);
});

it('injects the bridge into the canvas but never the public page', function (): void {
    $author = spaUser();
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Bridged', 'slug' => 'bridged',
        'blocks_data' => [[
            'id' => 'sec-b', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-b', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-b', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Bridged']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    $canvas = $this->actingAs($author)
        ->get(url('/pages-builder/'.$entry->getKey().'/canvas'))
        ->getContent();

    // Fingerprinted: the script is cached hard so the canvas reload does
    // not spend a round trip on it before the bridge can even say hello,
    // and the stamp is what stops an updated plugin serving a stale one.
    expect($canvas)->toMatch('~pages-builder/bridge\.js\?v=\d+~')
        // Empty columns are only a drop target if they have a size.
        ->and($canvas)->toContain('[data-magna-kind="column"]:not(:has(> *))');

    $this->get('/bridged')->assertOk()->assertDontSee('bridge.js', escape: false);
});

it('caches the bridge script privately, never in a shared cache', function (): void {
    $author = spaUser();

    // It is served behind an authorization check, so `private` is not a
    // nicety: a shared cache holding it would hand it to anyone.
    $this->actingAs($author)
        ->get(url('/pages-builder/bridge.js'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=86400, private');
});

it('lets the canvas be framed same-origin while everything else stays DENY', function (): void {
    $author = spaUser();
    $page = spaPage($author);

    // The canvas declares its own framing policy - the builder iframe is
    // its entire purpose - and the global security middleware must respect
    // it instead of appending DENY over it.
    $canvas = $this->actingAs($author)->get(url('/pages-builder/'.$page->getKey().'/canvas'));
    expect($canvas->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($canvas->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");

    // Every response that does NOT opt in keeps the hard default.
    $shell = $this->actingAs($author)->get(url('/pages-builder/edit/'.$page->getKey()));
    expect($shell->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('lists pages with builder links on the Pages admin screen', function (): void {
    $author = spaUser();
    spaPage($author);

    Livewire\Livewire::actingAs($author)
        ->test(PagesIndexPage::class)
        ->assertSee('Builder target')
        ->assertSee('Open builder');
});

it('creates a draft page from a title and heads to the builder', function (): void {
    $author = spaUser();

    Livewire\Livewire::actingAs($author)
        ->test(PagesIndexPage::class)
        ->set('newPageTitle', 'Fresh page')
        ->call('createPage');

    $created = Entry::type('page')->where('title', 'Fresh page')->first();
    expect($created)->not->toBeNull()
        ->and($created->getAttribute('slug'))->toBe('fresh-page');
});
