<?php

declare(strict_types=1);

/**
 * The menu builder's tree rules.
 *
 * Menus are stored nested and edited flat, so these are the rules that
 * decide what a drag or an arrow key actually does to somebody's
 * navigation. Every one of them is about the same promise: a row owns
 * what is under it, and no gesture may quietly leave a child behind or
 * strand it under a parent that moved away.
 */

use Magna\Pages\Menus\MenuTree;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

/** Rows as `label:depth`, which is the whole state a move can change. */
function rows(string ...$spec): array
{
    return array_map(static function (string $entry): array {
        [$label, $depth] = explode(':', $entry);

        return ['key' => $label, 'label' => $label, 'depth' => (int) $depth];
    }, $spec);
}

/** @param list<array<string, mixed>> $rows */
function shape(array $rows): array
{
    return array_map(
        static fn (array $row): string => $row['label'].':'.$row['depth'],
        $rows,
    );
}

function indexOf(array $rows, string $label): int
{
    foreach ($rows as $index => $row) {
        if ($row['label'] === $label) {
            return $index;
        }
    }

    return -1;
}

// ── Flat and nested are the same tree ────────────────────────────────────────

it('round-trips a nested tree through the flat editor shape', function (): void {
    $nested = [
        ['label' => 'Home', 'type' => 'url'],
        ['label' => 'About', 'type' => 'url', 'children' => [
            ['label' => 'Team', 'type' => 'url', 'children' => [
                ['label' => 'Board', 'type' => 'url'],
            ]],
            ['label' => 'History', 'type' => 'url'],
        ]],
    ];

    $flat = MenuTree::flatten($nested);

    expect(shape($flat))->toBe(['Home:0', 'About:0', 'Team:1', 'Board:2', 'History:1']);

    // Back out again unchanged — the editor is a view of the tree, not a
    // second version of it.
    expect(MenuTree::nest($flat))->toBe($nested);
});

it('gives every row a key, so the editor can track one across a move', function (): void {
    $flat = MenuTree::flatten([['label' => 'Home', 'type' => 'url']]);

    expect($flat[0]['key'])->toBeString()->not->toBeEmpty();
    // And the key is editor-only: it never reaches the stored tree.
    expect(MenuTree::nest($flat)[0])->not->toHaveKey('key');
});

it('refuses a depth that has no parent to hang from', function (): void {
    // A hand-edited payload claiming depth 3 under a root item describes a
    // tree with no middle, so the depth is brought back to what exists.
    expect(shape(MenuTree::normalise(rows('A:0', 'B:3', 'C:9'))))
        ->toBe(['A:0', 'B:1', 'C:2']);

    // The first row is always a root, whatever it claims.
    expect(shape(MenuTree::normalise(rows('A:4'))))->toBe(['A:0']);
});

// ── Moving carries the subtree ───────────────────────────────────────────────

it('steps over a sibling rather than into it', function (): void {
    $rows = rows('A:0', 'B:0', 'B1:1', 'C:0');

    // A moving down passes the whole of B, children included.
    expect(shape(MenuTree::moveDown($rows, 0)))->toBe(['B:0', 'B1:1', 'A:0', 'C:0']);

    // And back up again.
    expect(shape(MenuTree::moveUp(MenuTree::moveDown($rows, 0), 2)))
        ->toBe(['A:0', 'B:0', 'B1:1', 'C:0']);
});

it('takes a parent’s children with it', function (): void {
    $rows = rows('A:0', 'B:0', 'B1:1', 'B2:1');

    expect(shape(MenuTree::moveUp($rows, 1)))->toBe(['B:0', 'B1:1', 'B2:1', 'A:0']);
});

it('will not move the first row up or the last row down', function (): void {
    $rows = rows('A:0', 'B:0');

    expect(shape(MenuTree::moveUp($rows, 0)))->toBe(['A:0', 'B:0'])
        ->and(shape(MenuTree::moveDown($rows, 1)))->toBe(['A:0', 'B:0']);
});

it('moves a row to the top as a root item', function (): void {
    $rows = rows('A:0', 'B:1', 'C:2');

    // C was two levels deep; at the top of the list there is nothing for it
    // to be a child of.
    expect(shape(MenuTree::toTop($rows, 2)))->toBe(['C:0', 'A:0', 'B:1']);
});

// ── Depth ────────────────────────────────────────────────────────────────────

it('nests a row under the one above it, descendants and all', function (): void {
    $rows = rows('A:0', 'B:0', 'B1:1');

    expect(shape(MenuTree::indent($rows, 1)))->toBe(['A:0', 'B:1', 'B1:2']);
});

it('refuses to nest the first row, or one already nested under its neighbour', function (): void {
    expect(shape(MenuTree::indent(rows('A:0', 'B:0'), 0)))->toBe(['A:0', 'B:0']);

    // B is already A's first child; there is no deeper place under A.
    expect(shape(MenuTree::indent(rows('A:0', 'B:1'), 1)))->toBe(['A:0', 'B:1']);
});

it('refuses to nest past the depth the builder allows', function (): void {
    $spec = [];
    for ($depth = 0; $depth <= MenuTree::MAX_DEPTH; $depth++) {
        $spec[] = "L{$depth}:{$depth}";
    }
    $rows = rows(...$spec);

    // The deepest row is already at the cap, so its parent cannot go deeper
    // without taking it past.
    $before = shape($rows);
    expect(shape(MenuTree::indent($rows, count($rows) - 1)))->toBe($before);
});

it('lifts a row out from under its parent, keeping its own children', function (): void {
    $rows = rows('A:0', 'B:1', 'B1:2');

    expect(shape(MenuTree::outdent($rows, 1)))->toBe(['A:0', 'B:0', 'B1:1']);
    // A root row has nowhere further out to go.
    expect(shape(MenuTree::outdent(rows('A:0'), 0)))->toBe(['A:0']);
});

// ── Removing and dragging ────────────────────────────────────────────────────

it('removes a row and everything under it', function (): void {
    $rows = rows('A:0', 'B:0', 'B1:1', 'B2:1', 'C:0');

    expect(shape(MenuTree::remove($rows, 1)))->toBe(['A:0', 'C:0']);
});

it('drops a dragged row at a position and a depth in one move', function (): void {
    $rows = rows('A:0', 'B:0', 'C:0');

    // C dragged up under A.
    expect(shape(MenuTree::move($rows, 2, 1, 1)))->toBe(['A:0', 'C:1', 'B:0']);
});

it('refuses to drop a row inside its own subtree', function (): void {
    $rows = rows('A:0', 'A1:1', 'A2:1', 'B:0');

    // Dropping A among its own children would detach the list from itself.
    expect(shape(MenuTree::move($rows, 0, 2, 1)))->toBe(['A:0', 'A1:1', 'A2:1', 'B:0']);
});

it('keeps a dragged subtree’s internal shape', function (): void {
    $rows = rows('A:0', 'B:0', 'B1:1', 'B1a:2');

    // B moves to the front and one level in — its own tree travels with it.
    expect(shape(MenuTree::move($rows, 1, 0, 0)))->toBe(['B:0', 'B1:1', 'B1a:2', 'A:0']);
});
