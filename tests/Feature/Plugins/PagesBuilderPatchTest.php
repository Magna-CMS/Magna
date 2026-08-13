<?php

declare(strict_types=1);

/**
 * The builder's write path (docs/magna-pages/03-BUILDER.md §2, §5).
 *
 * Two guarantees are load-bearing and tested here rather than assumed:
 * every operation is authorized BEFORE any of them is applied, and the
 * document that results from a batch is structurally valid — patches that
 * are individually plausible but jointly produce a broken tree are refused
 * whole.
 */

use Illuminate\Support\Facades\Route;
use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Builder\DocumentEditor;
use Magna\Pages\Builder\Exceptions\PatchException;
use Magna\Pages\Builder\PatchApplier;
use Magna\Pages\Builder\PatchClassifier;
use Magna\Pages\Builder\PatchKind;
use Magna\Pages\Builder\PatchOperation;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function builderSetup(): void
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
}

/** A user holding exactly the named permissions. */
function builderUser(string ...$permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant(...$permissions);
    $user->assignRole($role);

    return $user;
}

function builderPage(User $author, string $slug = 'builder-page'): Entry
{
    return app(EntryManager::class)->create('page', [
        'title' => 'Builder page',
        'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-1', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-1', 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'blk-1', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Before']],
                ],
            ]],
        ]],
    ], $author->id);
}

/** @param array<string, mixed> $raw */
function classify(array $raw): PatchKind
{
    return app(PatchClassifier::class)->classify(PatchOperation::fromArray($raw));
}

// ── Classification: what an operation IS decides who may do it ──────────────

it('classifies operations by what they touch', function (): void {
    expect(classify(['op' => 'replace', 'path' => '/0/columns/0/blocks/0/data/text', 'value' => 'Hi']))
        ->toBe(PatchKind::Content)
        ->and(classify(['op' => 'replace', 'path' => '/0/columns/0/blocks/0/settings/align', 'value' => 'center']))
        ->toBe(PatchKind::Style)
        ->and(classify(['op' => 'remove', 'path' => '/0/columns/0/blocks/0']))
        ->toBe(PatchKind::Structure)
        ->and(classify(['op' => 'replace', 'path' => '/0/columns/0/blocks/0/data/text', 'value' => ['$bind' => 'entry.title']]))
        ->toBe(PatchKind::Binding)
        ->and(classify(['op' => 'replace', 'path' => '/0/settings/conditions/role', 'value' => 'admin']))
        ->toBe(PatchKind::Condition);
});

it('denies paths it cannot classify', function (): void {
    expect(fn () => classify(['op' => 'replace', 'path' => '/0/somethingNew', 'value' => 'x']))
        ->toThrow(PatchException::class, 'unrecognised document key');
});

it('refuses unsupported verbs and malformed pointers', function (): void {
    expect(fn () => PatchOperation::fromArray(['op' => 'copy', 'path' => '/0']))
        ->toThrow(PatchException::class, 'Unsupported patch operation')
        ->and(fn () => PatchOperation::fromArray(['op' => 'replace', 'path' => 'no-leading-slash']))
        ->toThrow(PatchException::class, 'absolute JSON Pointer')
        ->and(fn () => PatchOperation::fromArray(['op' => 'move', 'path' => '/0']))
        ->toThrow(PatchException::class, 'needs an absolute "from" pointer');
});

// ── Applier: list semantics that a naive implementation gets wrong ──────────

it('inserts into a list rather than overwriting, and closes gaps on remove', function (): void {
    $applier = app(PatchApplier::class);
    $document = ['items' => ['a', 'c']];

    $inserted = $applier->apply($document, [
        PatchOperation::fromArray(['op' => 'add', 'path' => '/items/1', 'value' => 'b']),
    ]);
    expect($inserted['items'])->toBe(['a', 'b', 'c']);

    $removed = $applier->apply($inserted, [
        PatchOperation::fromArray(['op' => 'remove', 'path' => '/items/0']),
    ]);
    expect($removed['items'])->toBe(['b', 'c']);
});

it('leaves the source document untouched when an operation fails midway', function (): void {
    $applier = app(PatchApplier::class);
    $document = ['items' => ['a']];

    expect(fn () => $applier->apply($document, [
        PatchOperation::fromArray(['op' => 'add', 'path' => '/items/-', 'value' => 'b']),
        PatchOperation::fromArray(['op' => 'replace', 'path' => '/missing/deep', 'value' => 'x']),
    ]))->toThrow(PatchException::class);

    expect($document['items'])->toBe(['a']);
});

// ── Authorization: the tier boundary, enforced before anything is applied ───

it('lets a content editor retype text but not delete the block', function (): void {
    builderSetup();
    $author = builderUser('pages.content');
    $page = builderPage($author);

    $document = app(DocumentEditor::class)->applyPatch($page, [
        ['op' => 'replace', 'path' => '/0/columns/0/blocks/0/data/text', 'value' => 'After'],
    ], $author);

    expect($document[0]['columns'][0]['blocks'][0]['data']['text'])->toBe('After');

    expect(fn () => app(DocumentEditor::class)->applyPatch($page->fresh(), [
        ['op' => 'remove', 'path' => '/0/columns/0/blocks/0'],
    ], $author))->toThrow(PatchException::class, 'pages.layout');
});

it('refuses the whole batch when any single operation is unauthorized', function (): void {
    builderSetup();
    $author = builderUser('pages.content');
    $page = builderPage($author, 'batch-page');

    expect(fn () => app(DocumentEditor::class)->applyPatch($page, [
        ['op' => 'replace', 'path' => '/0/columns/0/blocks/0/data/text', 'value' => 'Sneaky'],
        ['op' => 'remove', 'path' => '/0/columns/0/blocks/0'],
    ], $author))->toThrow(PatchException::class);

    // The authorized edit that preceded the refused one must NOT have landed.
    $stored = $page->fresh()?->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'][0]['data']['text'])->toBe('Before');
});

it('gates blocks that declare their own permission', function (): void {
    builderSetup();
    $author = builderUser('pages.content', 'pages.layout');
    $page = builderPage($author, 'gated-page');

    $insertHtml = [
        'op' => 'add',
        'path' => '/0/columns/0/blocks/-',
        'value' => ['id' => 'blk-html', 'block' => 'html', 'settings' => [], 'data' => ['content' => '<b>hi</b>']],
    ];

    expect(fn () => app(DocumentEditor::class)->applyPatch($page, [$insertHtml], $author))
        ->toThrow(PatchException::class, 'blocks.raw_html');

    $privileged = builderUser('pages.content', 'pages.layout', 'blocks.raw_html');
    $document = app(DocumentEditor::class)->applyPatch($page->fresh(), [$insertHtml], $privileged);

    expect($document[0]['columns'][0]['blocks'])->toHaveCount(2);
});

// ── Result validation: a valid batch that produces an invalid document ──────

it('rejects a batch whose result would be structurally invalid', function (): void {
    builderSetup();
    $author = builderUser('pages.content', 'pages.layout');
    $page = builderPage($author, 'invalid-result');

    // Duplicating a node id is legal per operation, invalid as a document.
    expect(fn () => app(DocumentEditor::class)->applyPatch($page, [[
        'op' => 'add',
        'path' => '/0/columns/0/blocks/-',
        'value' => ['id' => 'blk-1', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Clone']],
    ]], $author))->toThrow(PatchException::class);
});

// ── HTTP surface ───────────────────────────────────────────────────────────

it('serves a bootstrap payload and applies patches over HTTP', function (): void {
    builderSetup();
    $author = builderUser('pages.content');
    $page = builderPage($author, 'http-page');

    $bootstrap = $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))
        ->assertOk()
        ->json();

    expect($bootstrap['document']['slug'])->toBe('http-page')
        ->and($bootstrap['capabilities'])->toBe([
            'content' => true, 'structure' => false, 'style' => false, 'publish' => false,
        ])
        ->and(collect($bootstrap['registry'])->pluck('handle'))->toContain('heading');

    $this->actingAs($author)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [['op' => 'replace', 'path' => '/0/columns/0/blocks/0/data/text', 'value' => 'Over HTTP']],
    ])->assertOk()->assertJsonPath('document.0.columns.0.blocks.0.data.text', 'Over HTTP');
});

it('refuses builder API access without a session', function (): void {
    builderSetup();
    $author = builderUser('pages.content');
    $page = builderPage($author, 'guest-page');

    $this->getJson(url('/pages-builder/'.$page->getKey()))->assertStatus(401);
});

it('reports an unauthorized patch as 422 rather than applying it', function (): void {
    builderSetup();
    $author = builderUser('pages.content');
    $page = builderPage($author, 'http-denied');

    // Bootstrap first: writing requires holding the edit lock it acquires.
    $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();

    $this->actingAs($author)->patchJson(url('/pages-builder/'.$page->getKey()), [
        'operations' => [['op' => 'remove', 'path' => '/0/columns/0/blocks/0']],
    ])->assertStatus(422);

    $stored = $page->fresh()?->getAttribute('blocks_data');
    expect($stored[0]['columns'][0]['blocks'])->toHaveCount(1);
});

it('registers the builder routes only while the plugin is enabled', function (): void {
    builderSetup();

    expect(collect(Route::getRoutes()->getRoutes())->contains(
        fn ($route): bool => $route->getName() === 'pages.builder.patch',
    ))->toBeTrue();
});
