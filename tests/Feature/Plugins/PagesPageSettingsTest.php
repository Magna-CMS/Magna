<?php

declare(strict_types=1);

/**
 * Page settings — the ground a document is drawn on
 * (docs/magna-pages/13-BUILDER-CHROME-AND-CANVAS.md §4 F1).
 *
 * Two conditions shape the design and are asserted here because they are
 * the reason it looks the way it does:
 *
 * - a page with no settings renders byte-identically to how it rendered
 *   before this existed;
 * - all breakpoints emit into ONE body, so the shared page cache still
 *   applies — the same argument that made per-device values safe.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\PageSettings;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function pageSettingsUser(bool $withDesign = true): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $grants = ['panel.access', 'pages.content', 'pages.layout', 'pages.publish'];
    if ($withDesign) {
        $grants[] = 'pages.design';
    }
    $role->grant(...$grants);
    $user->assignRole($role);

    return $user;
}

function pageSettingsPage(User $author, string $slug): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Ground', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-ground', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-ground', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-ground', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Ground']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    return $entry;
}

it('paints the page it is set on', function (): void {
    $author = pageSettingsUser();
    $page = pageSettingsPage($author, 'painted-page');

    $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();
    $this->actingAs($author)->putJson(url('/pages-builder/'.$page->getKey().'/settings'), [
        'settings' => ['style' => ['background' => '#101820', 'color' => '#f8fafc']],
    ])->assertOk();

    $html = $this->get('/painted-page')->assertOk()->getContent();

    // On `body`, and emitted with the document's own stylesheet — a theme
    // replaces the layout file, so the head is not a safe place for this.
    expect($html)->toContain('body{')
        ->and($html)->toContain('background-color:#101820')
        ->and($html)->toContain('color:#f8fafc');
});

it('leaves a page with no settings exactly as it was', function (): void {
    $author = pageSettingsUser();
    pageSettingsPage($author, 'plain-ground');

    $html = (string) $this->get('/plain-ground')->assertOk()->getContent();

    // Nothing of this feature reaches a document that does not use it.
    expect(str_contains($html, 'body{'))->toBeFalse();
});

it('emits every breakpoint into one body and stays cacheable', function (): void {
    $author = pageSettingsUser();
    $page = pageSettingsPage($author, 'responsive-ground');

    $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();
    $this->actingAs($author)->putJson(url('/pages-builder/'.$page->getKey().'/settings'), [
        'settings' => ['style' => [
            'background' => ['$responsive' => ['base' => '#ffffff', 'mobile' => '#000000']],
        ]],
    ])->assertOk();

    // As a visitor: an authenticated request bypasses the page cache, and
    // the cache is the thing under test here.
    auth()->logout();

    $html = $this->get('/responsive-ground')->assertOk()
        ->assertHeader('X-Magna-Cache', 'miss')
        ->getContent();

    expect($html)->toContain('background-color:#ffffff')
        ->and($html)->toContain('@media (max-width: 767.98px)')
        ->and($html)->toContain('background-color:#000000');

    // The second visit is a HIT serving the same bytes: page settings did
    // not make the page per-visitor.
    $second = $this->get('/responsive-ground')->assertOk()->assertHeader('X-Magna-Cache', 'hit');
    expect($second->getContent())->toBe($html);
});

it('needs the design permission, not merely the content one', function (): void {
    $author = pageSettingsUser(withDesign: false);
    $page = pageSettingsPage($author, 'guarded-ground');

    $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();
    $this->actingAs($author)->putJson(url('/pages-builder/'.$page->getKey().'/settings'), [
        'settings' => ['style' => ['background' => '#ff0000']],
    ])->assertForbidden();

    expect(Entry::type('page')->find($page->getKey())?->getAttribute('page_settings'))->toBeNull();
});

it('refuses to write without the lock', function (): void {
    $author = pageSettingsUser();
    $other = pageSettingsUser();
    $page = pageSettingsPage($author, 'locked-ground');

    // The first actor takes the lock by opening the document.
    $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    $this->actingAs($other)->putJson(url('/pages-builder/'.$page->getKey().'/settings'), [
        'settings' => ['style' => ['background' => '#ff0000']],
    ])->assertStatus(409);
});

it('stores only what the page vocabulary declares', function (): void {
    // Unknown keys are dropped rather than refused: a builder one version
    // ahead of its server must not make a save fail.
    $clean = PageSettings::sanitize(['style' => [
        'background' => '#fff',
        'childGap' => '8px',        // a container key, not a page one
        'invented' => 'nonsense',
        'color' => ['not' => 'a value'],
    ]]);

    expect($clean)->toBe(['style' => ['background' => '#fff']]);
});

it('never lets a background image become anything but a url', function (): void {
    // The stored value is a URL and the emitted value is a url() function.
    // The author's string is not what gets emitted — which is the only
    // reason a value carrying parentheses may go near a stylesheet.
    foreach ([
        'https://evil.test/x.png");}body{display:none',
        'javascript:alert(1)',
        'data:image/svg+xml;base64,AAAA',
        'x.png") , url("y.png',
        '//evil.test/x.png',
    ] as $attempt) {
        $css = PageSettings::css(['style' => ['backgroundImage' => $attempt]]);

        expect($css)->toBe('', "accepted: {$attempt}");
    }

    // What it does accept, it wraps itself.
    expect(PageSettings::css(['style' => ['backgroundImage' => '/media/hero.jpg']]))
        ->toBe('body{background-image:url("/media/hero.jpg")}');
    expect(PageSettings::css(['style' => ['backgroundImage' => 'https://cdn.test/hero.jpg']]))
        ->toContain('url("https://cdn.test/hero.jpg")');
});

it('offers the page vocabulary to the builder', function (): void {
    $author = pageSettingsUser();
    $page = pageSettingsPage($author, 'vocab-ground');

    $payload = $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()->json();

    $keys = array_column($payload['styleControls']['page'], 'key');

    expect($keys)->toContain('background')
        ->and($keys)->toContain('backgroundImage')
        // A page has no gap between columns to set, so it is not offered.
        ->and($keys)->not->toContain('childGap')
        ->and($payload['document']['settings'])->toBe([]);
});
