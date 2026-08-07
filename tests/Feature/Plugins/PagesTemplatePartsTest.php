<?php

declare(strict_types=1);

/**
 * Template parts as content (§A3): headers/footers are entries — drafted,
 * revisioned, published like anything else. Published "header"/"footer"
 * parts replace theme chrome; ref sections splice reusable parts into
 * pages; drafts and missing parts degrade to nothing; part edits flush the
 * sitewide cache.
 */

use Illuminate\Support\Facades\DB;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function partsSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    return User::factory()->create();
}

function makePart(User $author, string $title, string $slug, string $headingText, bool $publish = true): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('pages_template', [
        'title' => $title,
        'slug' => $slug,
        'kind' => 'part',
        'blocks_data' => [[
            'id' => 'sec-part-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-part-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-part-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => $headingText]]],
            ]],
        ]],
    ], $author->id);

    return $publish ? $manager->publish($entry, actorId: $author->id) : $entry;
}

function partsPublishPage(User $author, string $slug, array $sections): Entry
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => ucfirst($slug), 'slug' => $slug, 'blocks_data' => $sections,
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

function simpleSection(string $id, string $headingText): array
{
    return [
        'id' => $id, 'type' => 'section', 'settings' => [],
        'columns' => [[
            'id' => $id.'-col', 'span' => 12, 'settings' => [],
            'blocks' => [['id' => $id.'-blk', 'block' => 'heading', 'settings' => [], 'data' => ['text' => $headingText]]],
        ]],
    ];
}

it('renders published header and footer parts around every page', function (): void {
    $author = partsSetup();
    makePart($author, 'Header', 'header', 'Designed header');
    makePart($author, 'Footer', 'footer', 'Designed footer');
    partsPublishPage($author, 'parts-page', [simpleSection('sec-body', 'Body content')]);

    $html = (string) $this->get('/parts-page')->assertOk()->getContent();

    expect($html)->toContain('Designed header')
        ->and($html)->toContain('Body content')
        ->and($html)->toContain('Designed footer');
});

it('splices a reusable part into a page via a ref section', function (): void {
    $author = partsSetup();
    makePart($author, 'CTA strip', 'cta-strip', 'Reused everywhere');

    partsPublishPage($author, 'ref-page', [
        simpleSection('sec-own', 'Own content'),
        ['id' => 'sec-ref', 'type' => 'ref', 'part' => 'cta-strip'],
    ]);

    $html = (string) $this->get('/ref-page')->assertOk()->getContent();

    expect($html)->toContain('Own content')
        ->and($html)->toContain('Reused everywhere');
});

it('degrades drafts and missing refs to nothing', function (): void {
    $author = partsSetup();
    makePart($author, 'Draft header', 'header', 'Unpublished header', publish: false);

    partsPublishPage($author, 'degrade-page', [
        simpleSection('sec-own', 'Still fine'),
        ['id' => 'sec-ghost', 'type' => 'ref', 'part' => 'no-such-part'],
    ]);

    $html = (string) $this->get('/degrade-page')->assertOk()->getContent();

    expect($html)->toContain('Still fine')
        ->and($html)->not->toContain('Unpublished header');
});

it('flushes the page cache when a template part changes', function (): void {
    $author = partsSetup();
    $header = makePart($author, 'Header', 'header', 'Version one');
    partsPublishPage($author, 'cached-with-part', [simpleSection('sec-b', 'Body')]);

    $this->get('/cached-with-part')->assertOk();
    expect(DB::table('pages_cache')->count())->toBe(1);

    app(EntryManager::class)->update($header, ['blocks_data' => [
        simpleSection('sec-part-header', 'Version two'),
    ]], $author->id);

    expect(DB::table('pages_cache')->count())->toBe(0);

    $this->get('/cached-with-part')->assertOk()->assertSee('Version two');
});
