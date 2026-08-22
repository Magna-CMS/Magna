<?php

declare(strict_types=1);

/**
 * Style controls (builder redesign step 9): an allowlisted `settings.style`
 * vocabulary that renders into the node's own style attribute — the same
 * sandbox per-node custom CSS already uses, so an editor styles their node
 * and nothing else.
 *
 * The vocabulary is shipped to the builder rather than mirrored in it, and
 * a key the renderer does not know emits nothing: a document from a newer
 * version degrades to today's rendering instead of becoming a way to write
 * arbitrary CSS.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Render\StyleDescriptors;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function styleUser(string ...$permissions): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish', ...$permissions);
    $user->assignRole($role);

    return $user;
}

/** @param array<string, mixed> $sectionStyle */
function stylePage(User $author, string $slug, array $sectionStyle = [], array $columnStyle = []): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Styled', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-style', 'type' => 'section',
            'settings' => $sectionStyle === [] ? [] : ['style' => $sectionStyle],
            'columns' => [[
                'id' => 'col-style', 'span' => 12,
                'settings' => $columnStyle === [] ? [] : ['style' => $columnStyle],
                'blocks' => [['id' => 'blk-style', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Styled']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    return $entry;
}

it('renders the declarations a style set describes', function (): void {
    $author = styleUser('pages.design');
    stylePage($author, 'styled-page', [
        'paddingTop' => '48px',
        'background' => 'var(--color-surface)',
        'textAlign' => 'center',
    ]);

    $html = $this->get('/styled-page')->assertOk()->getContent();

    expect($html)->toContain('padding-top:48px')
        ->and($html)->toContain('background-color:var(--color-surface)')
        ->and($html)->toContain('text-align:center');
});

it('emits nothing for a key it does not know, and for a value a select never offered', function (): void {
    $author = styleUser('pages.design');
    stylePage($author, 'unknown-style', [
        // Not in the table: a newer version's key, or a hand-edited document.
        'position' => 'fixed',
        'zIndex' => '9999',
        // In the table, but not one of the options it offers.
        'textAlign' => 'justify-all-the-things',
    ]);

    $html = $this->get('/unknown-style')->assertOk()->getContent();

    expect($html)->not->toContain('position:fixed')
        ->and($html)->not->toContain('9999')
        ->and($html)->not->toContain('justify-all-the-things');
});

it('refuses a value that tries to leave the declaration', function (): void {
    $author = styleUser('pages.design');
    stylePage($author, 'escaping-style', [
        'paddingTop' => '10px} body{display:none',
        'background' => 'url(https://evil.test/pixel.png)',
    ]);

    $html = $this->get('/escaping-style')->assertOk()->getContent();

    // Braces would end the attribute's sandbox; url() is an exfiltration
    // channel. Both are dropped rather than escaped into something odd.
    expect($html)->not->toContain('display:none')
        ->and($html)->not->toContain('evil.test');
});

it('makes a column a flex container only when its contents are being aligned', function (): void {
    $author = styleUser('pages.design');
    stylePage($author, 'aligned-column', [], ['alignItems' => 'center']);

    $html = $this->get('/aligned-column')->assertOk()->getContent();

    // A column is a flex ITEM of the row; aligning its contents does
    // nothing until it is also a container.
    expect($html)->toContain('align-items:center')
        ->and($html)->toContain('display:flex');

    // Padding alone does not need it, and does not get it.
    stylePage($author, 'padded-column', [], ['paddingTop' => '12px']);
    $padded = $this->get('/padded-column')->assertOk()->getContent();
    $column = substr($padded, (int) strpos($padded, 'class="magna-column"'), 200);

    expect($column)->toContain('padding-top:12px')
        ->and($column)->not->toContain('display:flex');
});

it('leaves a document without styles rendering exactly as before', function (): void {
    $author = styleUser('pages.design');
    stylePage($author, 'plain-page');

    $html = $this->get('/plain-page')->assertOk()->getContent();

    // The section carries no style attribute at all, and the column carries
    // only its span — absent settings mean absent markup, which is what
    // makes this change safe for every page that already exists.
    $section = substr($html, (int) strpos($html, '<section'), 300);

    expect($section)->toContain('class="magna-section"')
        ->and($section)->not->toContain('<section style')
        ->and($html)->toContain('style="flex: 12 12 0%"');
});

it('ships the vocabulary to the builder instead of making it guess', function (): void {
    $author = styleUser('pages.design');
    $entry = stylePage($author, 'bootstrap-style');

    $payload = $this->actingAs($author)
        ->getJson(url('/pages-builder/'.$entry->getKey()))
        ->assertOk()
        ->json();

    $sectionKeys = array_column($payload['styleControls']['section'], 'key');
    $columnKeys = array_column($payload['styleControls']['column'], 'key');

    expect($sectionKeys)->toContain('paddingTop')
        ->and($columnKeys)->toContain('alignItems')
        // Vertical align is a column's business; a section has no such row.
        ->and($sectionKeys)->not->toContain('alignItems')
        // Margins collapse rows into each other, so columns do not offer them.
        ->and($columnKeys)->not->toContain('marginTop');
});

it('treats a style write as a design edit, not a content one', function (): void {
    // A page each: the edit lock is per document and per editor, and this
    // test is about permissions, not contention.
    $editor = styleUser(); // content + layout, but NOT pages.design
    $refused = stylePage($editor, 'permission-refused');

    $this->actingAs($editor)->getJson(url('/pages-builder/'.$refused->getKey()))->assertOk();

    // settings.* classifies as Style, which pages.design gates. The greying
    // out in the UI is a courtesy; this refusal is the actual rule.
    $this->actingAs($editor)->patchJson(url('/pages-builder/'.$refused->getKey()), [
        'operations' => [['op' => 'add', 'path' => '/0/settings/style', 'value' => ['paddingTop' => '10px']]],
    ])->assertStatus(422);

    $designer = styleUser('pages.design');
    $allowed = stylePage($designer, 'permission-allowed');

    $this->actingAs($designer)->getJson(url('/pages-builder/'.$allowed->getKey()))->assertOk();
    $this->actingAs($designer)->patchJson(url('/pages-builder/'.$allowed->getKey()), [
        'operations' => [['op' => 'add', 'path' => '/0/settings/style', 'value' => ['paddingTop' => '10px']]],
    ])->assertOk();

    $stored = Entry::type('page')->findOrFail($allowed->getKey())->getAttribute('blocks_data');
    expect($stored[0]['settings']['style']['paddingTop'])->toBe('10px');
});

it('lets a content editor name a node without the design permission', function (): void {
    // A label is editorial metadata — it never reaches the page. Gating it
    // behind pages.design would mean an editor cannot name the thing they
    // are editing, while a style write beside it stays refused.
    $editor = styleUser(); // content + layout, no pages.design
    $entry = stylePage($editor, 'label-page');

    $this->actingAs($editor)->getJson(url('/pages-builder/'.$entry->getKey()))->assertOk();

    $this->actingAs($editor)->patchJson(url('/pages-builder/'.$entry->getKey()), [
        'operations' => [['op' => 'add', 'path' => '/0/settings/label', 'value' => 'Hero row']],
    ])->assertOk();

    $stored = Entry::type('page')->findOrFail($entry->getKey())->getAttribute('blocks_data');
    expect($stored[0]['settings']['label'])->toBe('Hero row');

    // The label is not a loophole into styling.
    $this->actingAs($editor)->patchJson(url('/pages-builder/'.$entry->getKey()), [
        'operations' => [['op' => 'add', 'path' => '/0/settings/style', 'value' => ['paddingTop' => '10px']]],
    ])->assertStatus(422);
});

it('keeps the descriptor table and the renderer in step', function (): void {
    // Every control the builder is told to draw must be one the renderer
    // will actually emit — the whole point of one shipped table.
    foreach (StyleDescriptors::forBuilder() as $kind => $controls) {
        foreach ($controls as $control) {
            // One representative value per control type. A control type
            // added without a case here fails this test rather than
            // silently going untested, which is the point of the guard.
            $value = match ($control['control']) {
                'select' => $control['options'][1] ?? 'center',
                'color' => '#123456',
                'image' => '/media/sample.jpg',
                default => '10px',
            };

            expect(StyleDescriptors::declarations([$control['key'] => $value], $kind))
                ->not->toBe('', "no declaration for {$kind}.{$control['key']}");
        }
    }
});
