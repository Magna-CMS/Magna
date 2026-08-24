<?php

declare(strict_types=1);

/**
 * The builder canvas (docs/magna-pages/03-BUILDER.md §2, §8).
 *
 * The claim being tested is the architectural one: the canvas is the
 * PUBLISHED page with node markers, not a second rendering of it. So the
 * public page must stay marker-free, the canvas must carry a marker for
 * every node, and a fragment re-render must produce the same HTML the full
 * page produced for that node.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Render\BuilderMarkup;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function canvasSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('pages.content');
    $user->assignRole($role);

    return $user;
}

function canvasPage(User $author, string $slug = 'canvas-page'): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Canvas page',
        'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-c', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-c', 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'blk-c', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Canvas heading']],
                ],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

// ── Marker injection: attributes, never new elements ────────────────────────

it('marks the existing outermost element instead of wrapping it', function (): void {
    $markup = new BuilderMarkup;

    expect($markup->mark('<h2 class="x">Hi</h2>', 'blk-1', 'block'))
        ->toBe('<h2 data-magna-node="blk-1" data-magna-kind="block" class="x">Hi</h2>');
});

it('skips leading comments and whitespace when finding the element', function (): void {
    $markup = new BuilderMarkup;

    expect($markup->mark("\n  <!-- a note -->\n<div>Hi</div>", 'blk-2', 'block'))
        ->toContain('<div data-magna-node="blk-2"')
        ->and($markup->mark("\n  <!-- a note -->\n<div>Hi</div>", 'blk-2', 'block'))
        ->toContain('<!-- a note -->');
});

it('wraps only when a block renders no element at all', function (): void {
    $markup = new BuilderMarkup;

    expect($markup->mark('bare text', 'blk-3', 'block'))
        ->toBe('<span data-magna-node="blk-3" data-magna-kind="block" style="display:contents">bare text</span>');
});

it('leaves a marker for a block that rendered nothing', function (): void {
    $markup = new BuilderMarkup;

    /*
     * This returned the empty string until it was found to be the reason a
     * freshly inserted image or download block could be neither seen nor
     * clicked: several blocks correctly render nothing until they are
     * given something, and with no node in the canvas the only way to
     * select one was the navigator.
     *
     * Builder-only. mark() is called from the sections partial exclusively
     * when $inBuilder, and the drift guarantee below covers the public
     * page.
     */
    expect($markup->mark('', 'blk-4', 'block'))
        ->toContain('data-magna-node="blk-4"')
        ->and($markup->mark('', 'blk-4', 'block'))->toContain('data-magna-empty="true"')
        // Scaffolding for a pointer, not content.
        ->and($markup->mark('', 'blk-4', 'block'))->toContain('aria-hidden="true"');
});

// ── The drift guarantee ────────────────────────────────────────────────────

it('keeps the public page free of builder markers', function (): void {
    $author = canvasSetup();
    canvasPage($author, 'public-clean');

    $this->get('/public-clean')
        ->assertOk()
        ->assertSee('Canvas heading')
        ->assertDontSee('data-magna-node', escape: false);
});

it('marks every node on the canvas', function (): void {
    $author = canvasSetup();
    $page = canvasPage($author, 'marked-canvas');

    $html = $this->actingAs($author)->get(url('/pages-builder/'.$page->getKey().'/canvas'))
        ->assertOk()
        ->getContent();

    // Canvas HTML reflects in-flight editor state — it must never be cached.
    expect($this->actingAs($author)->get(url('/pages-builder/'.$page->getKey().'/canvas'))
        ->headers->get('Cache-Control'))->toContain('no-store');

    expect($html)->toContain('data-magna-node="sec-c" data-magna-kind="section"')
        ->and($html)->toContain('data-magna-node="col-c" data-magna-kind="column"')
        ->and($html)->toContain('data-magna-node="blk-c" data-magna-kind="block"');
});

it('renders a fragment identical to that node inside the full canvas', function (): void {
    $author = canvasSetup();
    $page = canvasPage($author, 'fragment-match');

    $canvas = $this->actingAs($author)
        ->get(url('/pages-builder/'.$page->getKey().'/canvas'))
        ->getContent();

    $document = $page->getAttribute('blocks_data');

    $fragment = $this->actingAs($author)->postJson(url('/pages-builder/'.$page->getKey().'/fragment'), [
        'node' => 'blk-c',
        'document' => $document,
    ])->assertOk()->json('html');

    // The fragment the canvas would morph-swap in is byte-identical to what
    // the full page render produced for that node — the no-drift claim.
    expect($canvas)->toContain(trim((string) $fragment));
});

it('re-renders a fragment from unsaved document state', function (): void {
    $author = canvasSetup();
    $page = canvasPage($author, 'fragment-unsaved');

    $document = $page->getAttribute('blocks_data');
    $document[0]['columns'][0]['blocks'][0]['data']['text'] = 'Edited but not saved';

    $this->actingAs($author)->postJson(url('/pages-builder/'.$page->getKey().'/fragment'), [
        'node' => 'blk-c',
        'document' => $document,
    ])->assertOk()->assertJsonPath('node', 'blk-c');

    // Storage is untouched: the fragment endpoint renders, it never saves.
    $stored = $page->fresh()?->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'][0]['data']['text'])->toBe('Canvas heading');
});

it('validates posted document state before rendering it', function (): void {
    $author = canvasSetup();
    $page = canvasPage($author, 'fragment-invalid');

    $this->actingAs($author)->postJson(url('/pages-builder/'.$page->getKey().'/fragment'), [
        'node' => 'blk-c',
        'document' => [['id' => 'sec-x', 'type' => 'section', 'settings' => [], 'columns' => [
            ['id' => 'col-x', 'span' => 99, 'settings' => [], 'blocks' => []],
        ]]],
    ])->assertStatus(422);
});

it('404s an unknown node rather than rendering nothing silently', function (): void {
    $author = canvasSetup();
    $page = canvasPage($author, 'fragment-missing');

    $this->actingAs($author)->postJson(url('/pages-builder/'.$page->getKey().'/fragment'), [
        'node' => 'no-such-node',
        'document' => $page->getAttribute('blocks_data'),
    ])->assertStatus(404);
});

it('refuses canvas and fragment access without a session', function (): void {
    $author = canvasSetup();
    $page = canvasPage($author, 'canvas-guest');

    $this->get(url('/pages-builder/'.$page->getKey().'/canvas'))->assertRedirect();
    $this->postJson(url('/pages-builder/'.$page->getKey().'/fragment'), [
        'node' => 'blk-c', 'document' => [],
    ])->assertStatus(401);
});
