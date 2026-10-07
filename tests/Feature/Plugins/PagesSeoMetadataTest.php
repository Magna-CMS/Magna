<?php

declare(strict_types=1);

/**
 * What a page says about itself to something that is not a browser.
 *
 * A page's title is written for a visitor who is already here — "Home",
 * "Pricing" — and a theme appends the site name to it. A search result is
 * read by someone who has never been here, so a page may carry a full
 * title of its own and a sentence describing itself.
 *
 * Two rules follow, and both are asserted: an SEO title REPLACES the
 * theme's title-plus-site-name rather than being appended to it (the site
 * name is already in what the author wrote), and a page that sets neither
 * field renders exactly the head it rendered before these fields existed.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\SiteKit\SiteKit;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function seoAuthor(): User
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/**
 * @param  array<string, string>  $meta
 */
function seoPage(User $author, string $slug, array $meta = []): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', array_merge([
        'title' => 'Plain Title',
        'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-seo', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-seo', 'span' => 12, 'settings' => [],
                'blocks' => [[
                    'id' => 'blk-seo', 'block' => 'heading', 'settings' => [],
                    'data' => ['text' => 'On the page'],
                ]],
            ]],
        ]],
    ], $meta), $author->id);
    $manager->publish($entry, actorId: $author->id);

    return $entry;
}

it('prints the meta description a page carries', function (): void {
    $author = seoAuthor();
    seoPage($author, 'seo-described', [
        'meta_description' => 'One sentence describing the page.',
    ]);

    expect((string) $this->get('/seo-described')->assertOk()->getContent())
        ->toContain('<meta name="description" content="One sentence describing the page.">');
});

it('lets an SEO title stand alone instead of appending the site name', function (): void {
    $author = seoAuthor();
    seoPage($author, 'seo-titled', [
        'meta_title' => 'A Full Title Written For Search',
    ]);

    $html = (string) $this->get('/seo-titled')->assertOk()->getContent();

    expect($html)->toContain('<title>A Full Title Written For Search</title>')
        ->and(str_contains($html, 'Plain Title'))->toBeFalse();
});

it('leaves a page that sets neither exactly as it was', function (): void {
    $author = seoAuthor();
    seoPage($author, 'seo-bare');

    $html = (string) $this->get('/seo-bare')->assertOk()->getContent();

    expect($html)->toContain('Plain Title')
        ->and(str_contains($html, '<meta name="description"'))->toBeFalse();
});

it('carries the metadata through a site-kit round trip', function (): void {
    $author = seoAuthor();
    seoPage($author, 'seo-kit', [
        'meta_title' => 'Kit Title',
        'meta_description' => 'Kit description.',
    ]);

    $bundle = app(SiteKit::class)->build();
    $row = collect($bundle['pages'])->firstWhere('slug', 'seo-kit');

    expect($row['meta_title'])->toBe('Kit Title')
        ->and($row['meta_description'])->toBe('Kit description.');
});
