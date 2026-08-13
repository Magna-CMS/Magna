<?php

declare(strict_types=1);

/**
 * First public render path for magna/pages: the fallback route resolves
 * published pages by slug (and the configured home page at /), renders the
 * block document through the shared resolve seam, and degrades safely —
 * drafts and unknown URLs 404, maintenance mode 503s guests, richtext stays
 * sanitized on the public surface.
 */

use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\PagesSettings;
use Magna\Pages\Routing\PageRouteResolver;
use Magna\Plugins\PluginManager;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function pagesRenderingSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    return User::factory()->create();
}

/** @return array<mixed> */
function simpleDocument(string $headingText): array
{
    return [[
        'id' => 'sec-1', 'type' => 'section', 'settings' => [],
        'columns' => [[
            'id' => 'col-1', 'span' => 12, 'settings' => [],
            'blocks' => [
                ['id' => 'blk-1', 'block' => 'heading', 'settings' => [], 'data' => ['text' => $headingText]],
                ['id' => 'blk-2', 'block' => 'text', 'settings' => [], 'data' => ['body' => '<p>Body copy</p><script>alert(1)</script>']],
            ],
        ]],
    ]];
}

function publishPage(User $author, string $title, string $slug, ?array $document = null): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => $title,
        'slug' => $slug,
        'blocks_data' => $document ?? simpleDocument($title),
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('renders a published page at its slug with sanitized richtext', function (): void {
    $author = pagesRenderingSetup();
    publishPage($author, 'About us', 'about-us');

    $response = $this->get('/about-us');

    $response->assertOk()
        ->assertSee('About us')
        ->assertSee('<p>Body copy</p>', false);

    expect($response->getContent())->not->toContain('<script>');
});

it('serves the configured home page at the root path only via settings', function (): void {
    $author = pagesRenderingSetup();
    $home = publishPage($author, 'Welcome home', 'welcome-home');

    $settings = PagesSettings::get();
    $settings->home_page_id = (string) $home->getKey();
    app(SettingsRepository::class)->persist($settings);

    // Note: in the same-origin dev layout the panel owns '/', so the home
    // page only resolves where the fallback fires. We call the resolver
    // directly to pin the behavior without depending on panel routing.
    $resolved = app(PageRouteResolver::class)->resolve('/', PagesSettings::get());

    expect($resolved?->getKey())->toBe($home->getKey());
});

it('404s drafts and unknown slugs', function (): void {
    $author = pagesRenderingSetup();

    // create() on a draftable type starts as draft — never published.
    app(EntryManager::class)->create('page', [
        'title' => 'Secret draft',
        'slug' => 'secret-draft',
        'blocks_data' => simpleDocument('Secret draft'),
    ], $author->id);

    $this->get('/secret-draft')->assertNotFound();
    $this->get('/no-such-page')->assertNotFound();
});

it('renders the configured custom 404 page', function (): void {
    $author = pagesRenderingSetup();
    $notFound = publishPage($author, 'Lost in space', 'lost-in-space');

    $settings = PagesSettings::get();
    $settings->not_found_page_id = (string) $notFound->getKey();
    app(SettingsRepository::class)->persist($settings);

    $this->get('/definitely-missing')
        ->assertNotFound()
        ->assertSee('Lost in space');
});

it('serves maintenance mode to guests but not authenticated users', function (): void {
    $author = pagesRenderingSetup();
    publishPage($author, 'Public page', 'public-page');

    $settings = PagesSettings::get();
    $settings->maintenance_mode = true;
    app(SettingsRepository::class)->persist($settings);

    $this->get('/public-page')
        ->assertStatus(503)
        ->assertSee('maintenance');

    $this->actingAs($author)
        ->get('/public-page')
        ->assertOk()
        ->assertSee('Public page');
});

it('never renders unregistered blocks on the public page', function (): void {
    $author = pagesRenderingSetup();

    publishPage($author, 'Tolerant page', 'tolerant-page', [[
        'id' => 'sec-1', 'type' => 'section', 'settings' => [],
        'columns' => [[
            'id' => 'col-1', 'span' => 12, 'settings' => [],
            'blocks' => [
                ['id' => 'blk-known', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Still here']],
            ],
        ]],
    ]]);

    // Simulate a block from a since-disabled plugin directly in storage —
    // bypassing save validation the way real disabled-plugin data would.
    $entry = Entry::type('page')->where('slug', 'tolerant-page')->firstOrFail();
    $document = $entry->getAttribute('blocks_data');
    $document[0]['columns'][0]['blocks'][] = [
        'id' => 'blk-ghost', 'block' => 'vanished_plugin_block', 'settings' => [], 'data' => ['x' => 'y'],
    ];
    $entry->forceFill(['blocks_data' => $document])->save();

    $this->get('/tolerant-page')
        ->assertOk()
        ->assertSee('Still here')
        ->assertDontSee('vanished_plugin_block');
});

it('hides sections per device through visibility settings', function (): void {
    $author = pagesRenderingSetup();

    $entry = app(EntryManager::class)->create('page', [
        'title' => 'Responsive', 'slug' => 'responsive',
        'blocks_data' => [[
            'id' => 'sec-resp', 'type' => 'section',
            'settings' => ['visibility' => ['desktop' => true, 'tablet' => false, 'mobile' => false]],
            'columns' => [[
                'id' => 'col-resp', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-resp', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Desktop only']]],
            ]],
        ]],
    ], $author->id);
    app(EntryManager::class)->publish($entry, actorId: $author->id);

    $html = $this->get('/responsive')->assertOk()->getContent();

    // Hidden devices get their classes; visible ones do not. Space-prefixed
    // form asserts the CLASS ATTRIBUTE — the utility stylesheet's selectors
    // are dot-prefixed and must not satisfy (or break) these assertions.
    expect($html)->toContain(' magna-hide-tablet')
        ->and($html)->toContain(' magna-hide-mobile')
        ->and($html)->not->toContain(' magna-hide-desktop')
        ->and($html)->toContain('@media (max-width: 767.98px)');
});
