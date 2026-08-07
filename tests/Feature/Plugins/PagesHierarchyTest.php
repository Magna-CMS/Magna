<?php

declare(strict_types=1);

/**
 * Page hierarchy: hierarchical content types get parent/position/path
 * structural columns; nested URLs resolve off the materialized path in one
 * read; slug renames cascade descendant paths WITH per-URL 301s; cycles
 * are rejected.
 */

use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\Exceptions\SchemaException;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\Routing\PageRedirect;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function hierarchySetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    return User::factory()->create();
}

function hierarchyPage(User $author, string $title, string $slug, ?string $parentId = null): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => $title, 'slug' => $slug, 'parent_id' => $parentId,
        'blocks_data' => [[
            'id' => 'sec-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => $title]]],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('builds materialized paths and serves nested URLs', function (): void {
    $author = hierarchySetup();

    $docs = hierarchyPage($author, 'Docs', 'docs');
    $guides = hierarchyPage($author, 'Guides', 'guides', (string) $docs->getKey());
    $intro = hierarchyPage($author, 'Intro', 'intro', (string) $guides->getKey());

    expect($docs->path)->toBe('docs')
        ->and($guides->path)->toBe('docs/guides')
        ->and($intro->path)->toBe('docs/guides/intro');

    $this->get('/docs/guides/intro')->assertOk()->assertSee('Intro');
    $this->get('/docs/guides')->assertOk()->assertSee('Guides');
});

it('cascades a parent slug rename through descendant paths with 301s per moved URL', function (): void {
    $author = hierarchySetup();

    $docs = hierarchyPage($author, 'Docs', 'docs');
    hierarchyPage($author, 'Guides', 'guides', (string) $docs->getKey());

    app(EntryManager::class)->update($docs, ['slug' => 'handbook'], $author->id);

    $guides = Entry::type('page')->where('slug', 'guides')->firstOrFail();
    expect($guides->path)->toBe('handbook/guides');

    // Old URLs redirect — parent AND cascaded child.
    $this->get('/docs')->assertStatus(301)->assertHeader('Location', url('/handbook'));
    $this->get('/docs/guides')->assertStatus(301)->assertHeader('Location', url('/handbook/guides'));
    $this->get('/handbook/guides')->assertOk()->assertSee('Guides');

    expect(PageRedirect::query()->where('source_path', 'docs/guides')->exists())->toBeTrue();
});

it('rejects cycles and missing parents', function (): void {
    $author = hierarchySetup();

    $a = hierarchyPage($author, 'A', 'cycle-a');
    $b = hierarchyPage($author, 'B', 'cycle-b', (string) $a->getKey());

    expect(fn () => app(EntryManager::class)->update($a, ['parent_id' => (string) $b->getKey()], $author->id))
        ->toThrow(SchemaException::class, 'own descendant');

    expect(fn () => hierarchyPage($author, 'Orphan', 'orphan', '01HXNOPARENTULID0000000000'))
        ->toThrow(SchemaException::class, 'parent does not exist');
});

it('allows the same slug under different parents without suffixing', function (): void {
    $author = hierarchySetup();
    $manager = app(EntryManager::class);

    $docs = hierarchyPage($author, 'Docs', 'docs');
    $guides = hierarchyPage($author, 'Guides', 'guides');

    // Auto-slug (no explicit slug): both children titled "Intro" keep the
    // clean slug because dedup is scoped to the sibling set.
    $a = $manager->create('page', ['title' => 'Intro', 'parent_id' => (string) $docs->getKey()], $author->id);
    $b = $manager->create('page', ['title' => 'Intro', 'parent_id' => (string) $guides->getKey()], $author->id);

    expect($a->slug)->toBe('intro')
        ->and($b->slug)->toBe('intro')
        ->and($a->path)->toBe('docs/intro')
        ->and($b->path)->toBe('guides/intro');

    // Same title under the SAME parent still dedups.
    $c = $manager->create('page', ['title' => 'Intro', 'parent_id' => (string) $docs->getKey()], $author->id);
    expect($c->slug)->toBe('intro-2')->and($c->path)->toBe('docs/intro-2');
});

it('rejects an explicit slug that collides a sibling URL', function (): void {
    $author = hierarchySetup();

    $docs = hierarchyPage($author, 'Docs', 'docs');
    hierarchyPage($author, 'Intro', 'intro', (string) $docs->getKey());

    expect(fn () => hierarchyPage($author, 'Intro Two', 'intro', (string) $docs->getKey()))
        ->toThrow(SchemaException::class, 'already uses the URL path');
});

it('rejects a move that would collide two entries on one URL', function (): void {
    $author = hierarchySetup();

    $docs = hierarchyPage($author, 'Docs', 'docs');
    $guides = hierarchyPage($author, 'Guides', 'guides');
    hierarchyPage($author, 'Intro', 'intro', (string) $docs->getKey());
    $other = hierarchyPage($author, 'Intro', 'intro', (string) $guides->getKey());

    expect(fn () => app(EntryManager::class)->update($other, ['parent_id' => (string) $docs->getKey()], $author->id))
        ->toThrow(SchemaException::class, 'already uses the URL path');
});

it('keeps menu links pointing at full nested paths', function (): void {
    $author = hierarchySetup();

    $docs = hierarchyPage($author, 'Docs', 'docs');
    $guides = hierarchyPage($author, 'Guides', 'guides', (string) $docs->getKey());

    $menus = app(MenuManager::class);
    $menus->syncItems($menus->create('primary', 'Primary'), [
        ['label' => 'Guides', 'type' => 'page', 'page_id' => (string) $guides->getKey()],
    ]);

    expect($menus->resolve('primary')[0]['url'])->toBe('/docs/guides');
});
