<?php

declare(strict_types=1);

/**
 * Mounted collections (Phase C item 6, 05-SITE-STRUCTURE §1 + §C4): a
 * publiclyRenderable content type mounts at a URL prefix — the prefix
 * serves its archive, prefix/{slug} the single entry — inside the layout
 * shell. §C4 is the boundary: a type without the flag never mounts even
 * if the setting says so; only publicOnFrontend fields render or bind; a
 * site-designed {type}-single template document wins over the built-in
 * view, with bindings resolving against the entry.
 */

use Magna\Auth\Role;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\EntryStatus;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Pages\PagesSettings;
use Magna\Plugins\PluginManager;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function collectionsSetup(bool $renderable = true): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $schemaRegistry = app(SchemaRegistry::class);
    $schemaRegistry->register(ContentType::fromArray([
        'handle' => 'mount_article',
        'displayName' => 'Articles',
        'localizable' => false,
        'draftable' => false,
        'publiclyRenderable' => $renderable,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug'],
            ['handle' => 'excerpt', 'type' => 'textarea', 'publicOnFrontend' => true],
            ['handle' => 'internal_notes', 'type' => 'textarea'],
        ],
    ], app(FieldTypeRegistry::class)));
    app(SchemaSyncer::class)->syncAll($schemaRegistry, allowDestructive: true);

    $settings = PagesSettings::get();
    $settings->collection_mounts = [['type' => 'mount_article', 'prefix' => 'blog']];
    app(SettingsRepository::class)->persist($settings);

    return User::factory()->create();
}

function mountArticle(User $author, string $title, string $slug): Entry
{
    $entry = app(EntryManager::class)->create('mount_article', [
        'title' => $title, 'slug' => $slug,
        'excerpt' => "Public excerpt of {$title}",
        'internal_notes' => 'INTERNAL: do not leak',
    ], $author->id);

    $entry->forceFill([
        'status' => EntryStatus::Published->value,
        'published_at' => now(),
    ])->save();

    return $entry;
}

it('serves the archive and single views for a mounted type, public fields only', function (): void {
    $author = collectionsSetup();
    mountArticle($author, 'First Post', 'first-post');

    // Archive at the prefix, inside the shell.
    $archive = (string) $this->get('/blog')->assertOk()->getContent();
    expect($archive)->toContain('magna-collection--archive')
        ->and($archive)->toContain('First Post')
        ->and($archive)->toContain('href="/blog/first-post"')
        ->and($archive)->toContain('<main');

    // Single at prefix/slug: public field renders, private field never.
    $single = (string) $this->get('/blog/first-post')->assertOk()->getContent();
    expect($single)->toContain('First Post')
        ->and($single)->toContain('Public excerpt of First Post')
        ->and($single)->not->toContain('INTERNAL: do not leak');
});

it('never mounts a type without the publiclyRenderable flag', function (): void {
    $author = collectionsSetup(renderable: false);
    mountArticle($author, 'Hidden Post', 'hidden-post');

    // The setting points at the type, but §C4 says no.
    $this->get('/blog')->assertNotFound();
    $this->get('/blog/hidden-post')->assertNotFound();
});

it('renders a single through the site-designed template with §C4-gated bindings', function (): void {
    $author = collectionsSetup();
    mountArticle($author, 'Templated Post', 'templated-post');

    $manager = app(EntryManager::class);
    $template = $manager->create('pages_template', [
        'title' => 'Article single', 'slug' => 'mount-article-single', 'kind' => 'collection',
        'blocks_data' => [[
            'id' => 'sec-cs', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-cs', 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'blk-cs-t', 'block' => 'heading', 'settings' => [], 'data' => ['text' => ['$bind' => 'entry.title']]],
                    // publicOnFrontend field binds...
                    ['id' => 'blk-cs-e', 'block' => 'heading', 'settings' => [], 'data' => ['text' => ['$bind' => 'entry.excerpt']]],
                    // ...a private field binds to an empty gap, never a leak.
                    ['id' => 'blk-cs-n', 'block' => 'heading', 'settings' => [], 'data' => ['text' => ['$bind' => 'entry.internal_notes']]],
                ],
            ]],
        ]],
    ], $author->id);
    $manager->publish($template, actorId: $author->id);

    $html = (string) $this->get('/blog/templated-post')->assertOk()->getContent();

    expect($html)->toContain('Templated Post')
        ->and($html)->toContain('Public excerpt of Templated Post')
        ->and($html)->not->toContain('INTERNAL: do not leak')
        // Template replaced the built-in view.
        ->and($html)->not->toContain('magna-collection--single');
});

it('lets an exact page slug win over a mount prefix', function (): void {
    $author = collectionsSetup();

    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $author->assignRole($role);

    $manager = app(EntryManager::class);
    $page = $manager->create('page', [
        'title' => 'Owner Blog Page', 'slug' => 'blog',
        'blocks_data' => [[
            'id' => 'sec-ob', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-ob', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-ob', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'The owner wins']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($page, actorId: $author->id);

    $html = (string) $this->get('/blog')->assertOk()->getContent();
    expect($html)->toContain('The owner wins')
        ->and($html)->not->toContain('magna-collection--archive');
});
