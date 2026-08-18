<?php

declare(strict_types=1);

/**
 * Hierarchy maintenance across the FULL entry lifecycle — not just
 * create/update. restore(), publishDraftOf() and delete() must keep the
 * materialized path, collision guarantees, and descendant subtrees exactly
 * as consistent as update() does, and must roll back atomically when they
 * cannot.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\Exceptions\SchemaException;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\Models\Revision;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => 'tree_page',
        'displayName' => 'Tree Page',
        'draftable' => true,
        'hierarchical' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug', 'from' => 'title'],
        ],
    ], app(FieldTypeRegistry::class));

    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);
});

function treePage(string $title, string $slug, ?string $parentId = null): Entry
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('tree_page', [
        'title' => $title, 'slug' => $slug, 'parent_id' => $parentId,
    ]);

    return $manager->publish($entry);
}

// ── restore() ────────────────────────────────────────────────────────────────

it('restore() recomputes the path and cascades it to descendants', function (): void {
    $manager = app(EntryManager::class);

    $docs = treePage('Docs', 'docs');
    $guides = treePage('Guides', 'guides', (string) $docs->getKey());

    // Rename creates the revision holding the old slug.
    $manager->update($docs, ['slug' => 'handbook']);
    expect($docs->path)->toBe('handbook')
        ->and(Entry::type('tree_page')->findOrFail($guides->getKey())->path)->toBe('handbook/guides');

    $revision = Revision::query()
        ->where('entry_type', 'tree_page')->where('entry_id', $docs->getKey())
        ->orderBy('created_at')->firstOrFail();
    $restored = $manager->restore((string) $revision->id);

    expect((string) $restored->getAttribute('slug'))->toBe('docs')
        ->and($restored->path)->toBe('docs')
        ->and(Entry::type('tree_page')->findOrFail($guides->getKey())->path)->toBe('docs/guides');
});

it('restore() rebuilds a child path under its existing parent', function (): void {
    $manager = app(EntryManager::class);

    $docs = treePage('Docs', 'docs');
    $intro = treePage('Intro', 'intro', (string) $docs->getKey());

    $manager->update($intro, ['slug' => 'getting-started']);
    expect($intro->path)->toBe('docs/getting-started');

    $revision = Revision::query()
        ->where('entry_type', 'tree_page')->where('entry_id', $intro->getKey())
        ->orderBy('created_at')->firstOrFail();
    $restored = $manager->restore((string) $revision->id);

    expect($restored->path)->toBe('docs/intro');
});

it('restore() rejects a path collision and rolls the whole restore back', function (): void {
    $manager = app(EntryManager::class);

    $a = treePage('Alpha', 'alpha');
    $manager->update($a, ['slug' => 'beta']);   // revision now holds slug "alpha"
    treePage('Taken', 'alpha');                 // another entry claims the freed URL

    $revision = Revision::query()
        ->where('entry_type', 'tree_page')->where('entry_id', $a->getKey())
        ->orderBy('created_at')->firstOrFail();
    $countBefore = Revision::query()->where('entry_type', 'tree_page')->count();

    expect(fn () => $manager->restore((string) $revision->id))
        ->toThrow(SchemaException::class, 'already uses the URL path');

    $a = Entry::type('tree_page')->findOrFail($a->getKey());
    expect((string) $a->getAttribute('slug'))->toBe('beta')
        ->and($a->path)->toBe('beta')
        // The restore-point snapshot rolled back with the failed restore.
        ->and(Revision::query()->where('entry_type', 'tree_page')->count())->toBe($countBefore);
});

// ── publishDraftOf() ─────────────────────────────────────────────────────────

it('publishing a draft recomputes the path and cascades it to descendants', function (): void {
    $manager = app(EntryManager::class);

    $docs = treePage('Docs', 'docs');
    $guides = treePage('Guides', 'guides', (string) $docs->getKey());

    $draft = $manager->createDraftOf($docs);
    $manager->update($draft, ['slug' => 'handbook']);
    $republished = $manager->publish($draft);

    expect($republished->getKey())->toBe($docs->getKey())
        ->and((string) $republished->getAttribute('slug'))->toBe('handbook')
        ->and($republished->path)->toBe('handbook')
        ->and(Entry::type('tree_page')->findOrFail($guides->getKey())->path)->toBe('handbook/guides');
});

it('publishing a draft rejects a path collision and rolls the whole publish back', function (): void {
    $manager = app(EntryManager::class);

    $a = treePage('Alpha', 'alpha');
    $draft = $manager->createDraftOf($a);
    $manager->update($draft, ['slug' => 'gamma']);
    treePage('Gamma', 'gamma');                 // claims the URL the draft wants

    expect(fn () => $manager->publish($draft))
        ->toThrow(SchemaException::class, 'already uses the URL path');

    $a = Entry::type('tree_page')->findOrFail($a->getKey());
    expect((string) $a->getAttribute('slug'))->toBe('alpha')
        ->and($a->path)->toBe('alpha')
        // The draft survives the failed publish, still pending.
        ->and(Entry::type('tree_page')->where('draft_of', $a->getKey())->exists())->toBeTrue();
});

// ── delete() ─────────────────────────────────────────────────────────────────

it('deleting a parent promotes direct children to the grandparent with fresh paths', function (): void {
    $manager = app(EntryManager::class);

    $docs = treePage('Docs', 'docs');
    $guides = treePage('Guides', 'guides', (string) $docs->getKey());
    $intro = treePage('Intro', 'intro', (string) $guides->getKey());

    $manager->delete($guides);

    $intro = Entry::type('tree_page')->findOrFail($intro->getKey());
    expect($intro->parent_id)->toBe($docs->getKey())
        ->and($intro->path)->toBe('docs/intro');
});

it('deleting a root parent promotes children to the root and rewrites descendants', function (): void {
    $manager = app(EntryManager::class);

    $docs = treePage('Docs', 'docs');
    $guides = treePage('Guides', 'guides', (string) $docs->getKey());
    $intro = treePage('Intro', 'intro', (string) $guides->getKey());
    $deep = treePage('Deep', 'deep', (string) $intro->getKey());

    $manager->delete($docs);

    $guides = Entry::type('tree_page')->findOrFail($guides->getKey());
    $intro = Entry::type('tree_page')->findOrFail($intro->getKey());
    $deep = Entry::type('tree_page')->findOrFail($deep->getKey());

    expect($guides->parent_id)->toBeNull()
        ->and($guides->path)->toBe('guides')
        ->and($intro->parent_id)->toBe($guides->getKey())
        ->and($intro->path)->toBe('guides/intro')
        ->and($deep->path)->toBe('guides/intro/deep');
});

it('children remain editable after their parent is deleted', function (): void {
    $manager = app(EntryManager::class);

    $docs = treePage('Docs', 'docs');
    $guides = treePage('Guides', 'guides', (string) $docs->getKey());

    $manager->delete($docs);

    // Before the fix this threw "The selected parent does not exist."
    $guides = Entry::type('tree_page')->findOrFail($guides->getKey());
    $manager->update($guides, ['slug' => 'manuals']);

    expect($guides->path)->toBe('manuals');
});

it('a child may take over the URL its deleted parent freed', function (): void {
    $manager = app(EntryManager::class);

    $docs = treePage('Docs', 'docs');
    $child = treePage('Docs Child', 'docs', (string) $docs->getKey()); // path docs/docs

    $manager->delete($docs);

    $child = Entry::type('tree_page')->findOrFail($child->getKey());
    expect($child->parent_id)->toBeNull()
        ->and($child->path)->toBe('docs');
});

it('deleting a parent rolls back entirely when promoting a child would collide', function (): void {
    $manager = app(EntryManager::class);

    $docs = treePage('Docs', 'docs');
    $intro = treePage('Intro', 'intro', (string) $docs->getKey());
    treePage('Root Intro', 'intro');            // root URL /intro already taken

    expect(fn () => $manager->delete($docs))
        ->toThrow(SchemaException::class, 'already uses the URL path');

    // Nothing happened: parent still present, child untouched.
    $docs = Entry::type('tree_page')->findOrFail($docs->getKey());
    $intro = Entry::type('tree_page')->findOrFail($intro->getKey());
    expect($intro->parent_id)->toBe($docs->getKey())
        ->and($intro->path)->toBe('docs/intro');
});
