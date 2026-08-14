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

function rowLayoutUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

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
