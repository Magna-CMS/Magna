<?php

declare(strict_types=1);

/**
 * Automatic 301s on page slug renames + the two invariants that separate a
 * real redirect table from a support-ticket generator: chain collapse
 * (a→b→c becomes a→c) and the rename-back loop guard (a→b then b→a leaves
 * no loop). Live pages always beat stale redirects.
 */

use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Pages\Routing\PageRedirect;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function redirectsSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    return User::factory()->create();
}

function makePublishedPage(User $author, string $title, string $slug): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => $title,
        'slug' => $slug,
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

it('301s the old URL to the new one after a slug rename', function (): void {
    $author = redirectsSetup();
    $page = makePublishedPage($author, 'Our story', 'our-story');

    app(EntryManager::class)->update($page, ['slug' => 'about-our-story'], $author->id);

    $this->get('/our-story')
        ->assertStatus(301)
        ->assertHeader('Location', url('/about-our-story'));

    $this->get('/about-our-story')->assertOk()->assertSee('Our story');
});

it('collapses redirect chains to a single hop', function (): void {
    $author = redirectsSetup();
    $page = makePublishedPage($author, 'Chained', 'first-slug');
    $manager = app(EntryManager::class);

    $manager->update($page, ['slug' => 'second-slug'], $author->id);
    $manager->update($page, ['slug' => 'third-slug'], $author->id);

    // first-slug must point straight at third-slug — never through second.
    $first = PageRedirect::query()->where('source_path', 'first-slug')->firstOrFail();
    expect($first->target_path)->toBe('third-slug');

    $this->get('/first-slug')
        ->assertStatus(301)
        ->assertHeader('Location', url('/third-slug'));
});

it('guards against loops when a slug is renamed back', function (): void {
    $author = redirectsSetup();
    $page = makePublishedPage($author, 'Boomerang', 'original');
    $manager = app(EntryManager::class);

    $manager->update($page, ['slug' => 'temporary'], $author->id);
    $manager->update($page, ['slug' => 'original'], $author->id);

    // No redirect may exist FROM the live slug.
    expect(PageRedirect::query()->where('source_path', 'original')->exists())->toBeFalse();

    $this->get('/original')->assertOk()->assertSee('Boomerang');
    $this->get('/temporary')
        ->assertStatus(301)
        ->assertHeader('Location', url('/original'));
});

it('lets a live page win over a stale redirect', function (): void {
    $author = redirectsSetup();

    PageRedirect::query()->create([
        'source_path' => 'contested',
        'target_path' => 'elsewhere',
        'status' => 301,
        'automatic' => false,
    ]);

    makePublishedPage($author, 'The page wins', 'contested');

    $this->get('/contested')->assertOk()->assertSee('The page wins');
});

it('ignores slug changes on non-page entry types', function (): void {
    redirectsSetup();
    $author = User::factory()->create();

    // Register an unrelated type with a slug and rename through it.
    $schemaRegistry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => 'redirect_bystander',
        'displayName' => 'Bystander',
        'localizable' => false,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug'],
        ],
    ], app(FieldTypeRegistry::class));
    $schemaRegistry->register($type);
    app(SchemaSyncer::class)->syncAll($schemaRegistry, allowDestructive: true);

    $manager = app(EntryManager::class);
    $entry = $manager->create('redirect_bystander', ['title' => 'B', 'slug' => 'bystander-one'], $author->id);
    $manager->update($entry, ['slug' => 'bystander-two'], $author->id);

    expect(PageRedirect::query()->where('source_path', 'bystander-one')->exists())->toBeFalse();
});
