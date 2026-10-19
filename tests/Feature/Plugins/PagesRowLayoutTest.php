<?php

declare(strict_types=1);

/**
 * Row layout (builder redesign step 14): how a section distributes and
 * aligns its columns.
 *
 * The row is `.magna-columns`, a different element from the section, and
 * the section's own style attribute cannot reach it — so row declarations
 * ride the per-node stylesheet with a descendant selector. It is its own
 * style kind because `align-items` on a row lines the COLUMNS up while on
 * a column it lines that column's CONTENTS up, and one key meaning two
 * things is a key nobody can label.
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Pages\Render\ResponsiveStyles;
use Magna\Pages\Render\StyleDescriptors;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

// Every test in this file exercises the real pages plugin. A checkout
// without plugins-dev (CI clones core alone) skips the file rather than
// failing on classes it was never shipped.
beforeEach(function (): void {
    skipWithoutDevPlugin('magna-cms/pages');
});

function rowLayoutUser(): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.design', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/** @param array<string, mixed> $row */
function rowLayoutPage(User $author, string $slug, array $row): void
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Row', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-row', 'type' => 'section',
            'settings' => $row === [] ? [] : ['row' => $row],
            'columns' => [
                ['id' => 'col-a', 'span' => 6, 'settings' => [], 'blocks' => []],
                ['id' => 'col-b', 'span' => 6, 'settings' => [], 'blocks' => []],
            ],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);
}

it('styles the row rather than the section', function (): void {
    $author = rowLayoutUser();
    rowLayoutPage($author, 'row-layout', ['gap' => '3rem', 'rowAlign' => 'center']);

    $html = $this->get('/row-layout')->assertOk()->getContent();

    // The declarations target the columns wrapper, not the section: putting
    // align-items on the section would align nothing, since the section is
    // not the flex container.
    expect($html)->toContain('.magna-n-sec-row .magna-columns{')
        ->and($html)->toContain('gap:3rem')
        ->and($html)->toContain('align-items:center')
        ->and($html)->toContain('magna-n-sec-row');
});

it('takes per-device row values like any other style', function (): void {
    $author = rowLayoutUser();
    rowLayoutPage($author, 'row-responsive', [
        'rowDirection' => ['$responsive' => ['base' => 'row', 'mobile' => 'column']],
    ]);

    $html = $this->get('/row-responsive')->assertOk()->getContent();

    expect($html)->toContain('flex-direction:row')
        ->and($html)->toContain('@media (max-width: 767.98px)')
        ->and($html)->toContain('flex-direction:column !important');
});

it('leaves a section with no row settings untouched', function (): void {
    $author = rowLayoutUser();
    rowLayoutPage($author, 'row-plain', []);

    $html = $this->get('/row-plain')->assertOk()->getContent();

    expect($html)->not->toContain('magna-n-sec-row')
        ->and($html)->not->toContain('.magna-columns{');
});

it('stays cacheable', function (): void {
    $author = rowLayoutUser();
    rowLayoutPage($author, 'row-cached', ['gap' => '2rem']);

    $first = $this->get('/row-cached')->assertOk()->assertHeader('X-Magna-Cache', 'miss')->getContent();
    $second = $this->get('/row-cached')->assertOk()->assertHeader('X-Magna-Cache', 'hit')->getContent();

    expect($second)->toBe($first);
});

it('refuses a direction the row never offered', function (): void {
    // The options ARE the vocabulary: a select value outside them emits
    // nothing, the same rule every other select key follows.
    $css = ResponsiveStyles::rulesFor(
        'sec-1',
        ['rowDirection' => 'diagonal'],
        StyleDescriptors::ROW,
        withBase: true,
        within: '.magna-columns',
    );

    expect($css)->toBe('');
});

it('keeps row keys out of the section and column vocabularies', function (): void {
    $sectionKeys = array_column(StyleDescriptors::forKind(StyleDescriptors::SECTION), 'key');
    $rowKeys = array_column(StyleDescriptors::forKind(StyleDescriptors::ROW), 'key');

    expect($rowKeys)->toContain('gap')
        ->and($rowKeys)->toContain('rowAlign')
        ->and($sectionKeys)->not->toContain('gap')
        // A column aligns its own contents with alignItems; the row aligns
        // the columns with rowAlign. Two names, because two meanings.
        ->and($rowKeys)->not->toContain('alignItems');
});

/*
 * Exact column widths (settings.row.columnTemplate).
 *
 * `span` is twelfths, and twelfths cannot say 1.15 / 0.85. Until this key
 * existed the only way to render that ratio was a theme class switching the
 * row to grid behind the document's back — so the builder went on showing
 * `6, 6` for a band the page drew unequal, with nothing on screen admitting
 * the override. A real design hit it 26 times. The tests below are about the
 * two things that make the key worth having: what the document stores is what
 * the page renders, and a row that stores nothing renders exactly as before.
 */

it('renders a row ratio the span field could never hold', function (): void {
    $author = rowLayoutUser();
    rowLayoutPage($author, 'row-tracks', ['columnTemplate' => '1.15fr 0.85fr']);

    $html = $this->get('/row-tracks')->assertOk()->getContent();

    expect($html)->toContain('.magna-n-sec-row .magna-columns{')
        ->and($html)->toContain('grid-template-columns:1.15fr 0.85fr');
});

it('makes the row a grid, because nothing else would make the template apply', function (): void {
    $author = rowLayoutUser();
    rowLayoutPage($author, 'row-grid', ['columnTemplate' => '1.15fr 0.85fr']);

    $html = $this->get('/row-grid')->assertOk()->getContent();

    // Derived, never asked for: a row is flex and each column carries an
    // inline `flex: N N 0%` that a grid template would otherwise lose to.
    // Under grid that inline value stops applying on its own — which is why
    // there is no `!important` here and the span stays written as the
    // fallback for a row whose template is later removed.
    expect($html)->toContain('display:grid')
        ->and($html)->toContain('flex: 6 6 0%');
});

it('collapses on a phone only because the document says to', function (): void {
    $author = rowLayoutUser();
    rowLayoutPage($author, 'row-tracks-mobile', [
        'columnTemplate' => ['$responsive' => ['base' => '1.15fr 0.85fr', 'mobile' => '1fr']],
    ]);

    $html = $this->get('/row-tracks-mobile')->assertOk()->getContent();

    // A grid row does not inherit the one-column collapse the shell gives a
    // flex row, so the narrow value is stored rather than assumed. Assumed,
    // the builder could not show it, and the disagreement would be back.
    expect($html)->toContain('grid-template-columns:1.15fr 0.85fr')
        ->and($html)->toContain('@media (max-width: 767.98px)')
        ->and($html)->toContain('grid-template-columns:1fr !important');
});

it('refuses a track this row could not show the editor', function (string $template): void {
    // FR numbers only, by the rule the whole style table follows: a control
    // is offered when setting it will matter, and a track the column control
    // cannot display is the very disagreement the key exists to end.
    $css = ResponsiveStyles::rulesFor(
        'sec-1',
        ['columnTemplate' => $template],
        StyleDescriptors::ROW,
        withBase: true,
        within: '.magna-columns',
    );

    expect($css)->toBe('');
})->with([
    'a keyword track' => 'auto 1fr',
    'a percentage' => '1.15fr 20%',
    'numbers with no unit' => '1.15 0.85',
    'a zero track' => '0fr 1fr',
    'more tracks than the grid has columns' => '1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr',
    'anything with a function in it' => 'minmax(0, 1fr) 1fr',
]);

it('leaves a row that stores no template exactly as it was', function (): void {
    $author = rowLayoutUser();
    rowLayoutPage($author, 'row-no-tracks', ['gap' => '3rem']);

    $html = $this->get('/row-no-tracks')->assertOk()->getContent();

    expect($html)->toContain('gap:3rem')
        ->and($html)->not->toContain('display:grid')
        ->and($html)->not->toContain('grid-template-columns')
        ->and($html)->toContain('flex: 6 6 0%');
});

it('does not offer the template as one more field in the style panel', function (): void {
    // Its value is one number per COLUMN, and that panel does not know how
    // many columns a row has. It is drawn beside the column list, where a
    // width sits next to the column it belongs to.
    $rowKeys = array_column(StyleDescriptors::forKind(StyleDescriptors::ROW), 'key');

    expect($rowKeys)->not->toContain('columnTemplate')
        ->and($rowKeys)->toContain('gap');
});
