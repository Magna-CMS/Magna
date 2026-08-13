<?php

declare(strict_types=1);

/**
 * Dynamic tags (Phase C, RegistersDynamicTags): a page field bound to
 * `tag.<handle>` resolves through the core DynamicTagRegistry at render;
 * a tag's declared cacheability governs the shared page cache (CACHE_USER
 * and unknown tags keep the page out of it); a throwing resolver costs its
 * own gap, never the page; the bind picker lists registered tags.
 */

use Magna\Auth\Role;
use Magna\Blocks\DynamicTags\DynamicTag;
use Magna\Blocks\DynamicTags\DynamicTagRegistry;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Render\BindingResolver;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

final class FixtureUnreadCountTag implements DynamicTag
{
    public function handle(): string
    {
        return 'fixture.unread';
    }

    public function label(): string
    {
        return 'Unread messages';
    }

    public function cacheability(): string
    {
        return self::CACHE_USER;
    }

    public function resolve(): string
    {
        return '7 <b>unread</b>';
    }
}

final class FixtureBrokenTag implements DynamicTag
{
    public function handle(): string
    {
        return 'fixture.broken';
    }

    public function label(): string
    {
        return 'Broken tag';
    }

    public function cacheability(): string
    {
        return self::CACHE_STATIC;
    }

    public function resolve(): string
    {
        throw new RuntimeException('resolver exploded');
    }
}

function tagsSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
    app(DynamicTagRegistry::class)->register(new FixtureUnreadCountTag);
    app(DynamicTagRegistry::class)->register(new FixtureBrokenTag);

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/** @param array<string, mixed> $data */
function tagPage(User $author, string $slug, array $data): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Tag page', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-t-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-t-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-t-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => $data]],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('resolves a bound dynamic tag on the public page, escaped', function (): void {
    $author = tagsSetup();
    tagPage($author, 'tagged', ['text' => ['$bind' => 'tag.fixture.unread']]);

    $html = $this->get('/tagged')->assertOk()->getContent();

    // The value arrives as text — a tag cannot inject markup.
    expect($html)->toContain('7 &lt;b&gt;unread&lt;/b&gt;')
        ->and($html)->not->toContain('7 <b>unread</b>');
});

it('keeps a page binding a CACHE_USER tag out of the shared page cache', function (): void {
    $author = tagsSetup();
    tagPage($author, 'per-user', ['text' => ['$bind' => 'tag.fixture.unread']]);
    tagPage($author, 'evergreen', ['text' => ['$bind' => 'tag.site.year']]);

    // Per-user tag: never cached, on any request.
    $this->get('/per-user')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');
    $this->get('/per-user')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');

    // The built-in CACHE_STATIC year tag: cached like any other page.
    $this->get('/evergreen')->assertOk()->assertHeader('X-Magna-Cache', 'miss');
    $this->get('/evergreen')->assertOk()->assertHeader('X-Magna-Cache', 'hit');
});

it('treats a vanished tag as uncacheable and renders it as a gap', function (): void {
    $author = tagsSetup();
    tagPage($author, 'gone-tag', ['text' => ['$bind' => 'tag.vanished.plugin-tag']]);

    // Renders fine, empty where the tag was — and stays out of the cache,
    // so the gap cannot outlive the plugin's recovery.
    $this->get('/gone-tag')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');
});

it('renders a gap when a tag resolver throws, without breaking the page', function (): void {
    $author = tagsSetup();
    tagPage($author, 'broken-tag', ['text' => ['$bind' => 'tag.fixture.broken']]);

    $html = $this->get('/broken-tag')->assertOk()->getContent();
    expect($html)->not->toContain('resolver exploded');
});

it('lists registered tags in the bind picker sources', function (): void {
    tagsSetup();

    $sources = app(BindingResolver::class)->sources();

    expect($sources)->toHaveKey('tag.fixture.unread')
        ->and($sources['tag.fixture.unread'])->toBe('Unread messages')
        // The plugin's own built-in tag arrives through the same
        // RegistersDynamicTags wiring third-party plugins use.
        ->and($sources)->toHaveKey('tag.site.year')
        ->and($sources['tag.site.year'])->toBe('Current year');
});

it('resolves inline {tag:…} tokens inside text, escaped, with unknown tags as gaps', function (): void {
    $author = tagsSetup();
    tagPage($author, 'inline-tags', [
        'text' => 'You have {tag:fixture.unread} messages. {tag:vanished.tag} Bye.',
    ]);

    $html = $this->get('/inline-tags')->assertOk()->getContent();

    // Substituted mid-sentence, value escaped, unknown token vanishes.
    expect($html)->toContain('You have 7 &lt;b&gt;unread&lt;/b&gt; messages.')
        ->and($html)->not->toContain('{tag:')
        ->and($html)->not->toContain('<b>unread</b>');
});

it('folds inline tag cacheability into the page cache verdict', function (): void {
    $author = tagsSetup();
    tagPage($author, 'inline-user', ['text' => 'Unread: {tag:fixture.unread}']);
    tagPage($author, 'inline-year', ['text' => 'Since {tag:site.year}']);

    // CACHE_USER inline token: never cached.
    $this->get('/inline-user')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');

    // CACHE_STATIC inline token: cached like any page.
    $this->get('/inline-year')->assertOk()->assertHeader('X-Magna-Cache', 'miss');
    $this->get('/inline-year')->assertOk()->assertHeader('X-Magna-Cache', 'hit');
});

it('resolves the built-in current-year tag to this year', function (): void {
    $author = tagsSetup();
    tagPage($author, 'year-page', ['text' => ['$bind' => 'tag.site.year']]);

    $this->get('/year-page')->assertOk()->assertSee(now()->format('Y'));
});
