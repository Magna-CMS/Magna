<?php

declare(strict_types=1);

/**
 * Pruning the pages the end-to-end suite leaves behind.
 *
 * The suite runs against a live install on purpose, so every run creates
 * real content. This is what stops it accumulating — and the thing worth
 * testing about a bulk-delete command is not that it deletes, but that it
 * deletes ONLY what it claims to.
 */

use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function pruneFixtureAuthor(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    return User::factory()->create();
}

function makePage(User $author, string $slug): Entry
{
    return app(EntryManager::class)->create('page', [
        'title' => $slug, 'slug' => $slug, 'blocks_data' => [],
    ], $author->id);
}

it('deletes fixtures and nothing else', function (): void {
    $author = pruneFixtureAuthor();

    $fixture = makePage($author, 'e2e-1787470030295');
    $real = makePage($author, 'about');
    // A page a person might plausibly name with a number in it. The
    // pattern is a 13-digit millisecond stamp, not "has digits".
    $lookalike = makePage($author, 'top-100-of-2024');
    $shortNumber = makePage($author, 'suite-2-1234567890');

    $this->artisan('magna:pages:prune-fixtures')->assertSuccessful();

    expect(Entry::type('page')->find($fixture->getKey()))->toBeNull()
        ->and(Entry::type('page')->find($real->getKey()))->not->toBeNull()
        ->and(Entry::type('page')->find($lookalike->getKey()))->not->toBeNull()
        ->and(Entry::type('page')->find($shortNumber->getKey()))->not->toBeNull();
});

it('prunes template fixtures too, since the suite makes those as well', function (): void {
    $author = pruneFixtureAuthor();

    $part = app(EntryManager::class)->create('pages_template', [
        'title' => 'Brand header', 'slug' => 'brand-header-1787472374280',
        'kind' => 'part', 'role' => 'header', 'blocks_data' => [],
    ], $author->id);

    $keep = app(EntryManager::class)->create('pages_template', [
        'title' => 'Header', 'slug' => 'header', 'kind' => 'part', 'blocks_data' => [],
    ], $author->id);

    $this->artisan('magna:pages:prune-fixtures')->assertSuccessful();

    expect(Entry::type('pages_template')->find($part->getKey()))->toBeNull()
        ->and(Entry::type('pages_template')->find($keep->getKey()))->not->toBeNull();
});

it('reports what it would do without doing it', function (): void {
    $author = pruneFixtureAuthor();
    $fixture = makePage($author, 'e2e-1787470030295');

    $this->artisan('magna:pages:prune-fixtures --dry-run')->assertSuccessful();

    expect(Entry::type('page')->find($fixture->getKey()))->not->toBeNull();
});

it('refuses to run in production at all', function (): void {
    pruneFixtureAuthor();
    app()->detectEnvironment(fn (): string => 'production');

    // A command whose job is bulk deletion has no business running
    // anywhere real, however narrow its pattern is.
    $this->artisan('magna:pages:prune-fixtures')->assertFailed();
});
