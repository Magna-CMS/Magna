<?php

declare(strict_types=1);

/**
 * Headers and footers (docs/magna-pages/13-BUILDER-CHROME-AND-CANVAS.md §4 F3).
 *
 * The resolution chain IS the feature, so every rung gets its own test:
 *
 *   1. the page's own choice — what "override" means;
 *   2. the site default, set once in settings;
 *   3. a published part whose slug is literally "header"/"footer".
 *
 * The third rung is the one that matters most here. It is how every site
 * chose its chrome before any of this existed, and a site that never opens
 * the new settings must not notice this feature at all.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\PagesSettings;
use Magna\Pages\Templates\TemplatePartResolver;
use Magna\Plugins\PluginManager;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function chromeAuthor(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
    app(ThemeManager::class)->activate('magna/launch');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.design', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/** @param array<string, mixed> $extra */
function chromePart(User $author, string $slug, string $text, array $extra = []): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('pages_template', array_merge([
        'title' => ucfirst($slug), 'slug' => $slug, 'kind' => 'part',
        'blocks_data' => [[
            'id' => 'sec-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => $text]]],
            ]],
        ]],
    ], $extra), $author->id);
    $manager->publish($entry, actorId: $author->id);

    return $entry;
}

function chromePage(User $author, string $slug, array $settings = []): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', array_merge([
        'title' => 'Chrome', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-body', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-body', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-body', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Body']]],
            ]],
        ]],
    ], $settings === [] ? [] : ['page_settings' => $settings]), $author->id);
    $manager->publish($entry, actorId: $author->id);

    return $entry;
}

function setChromeDefault(string $role, ?string $id): void
{
    $settings = PagesSettings::get();
    if ($role === 'header') {
        $settings->default_header_id = $id;
    } else {
        $settings->default_footer_id = $id;
    }
    app(SettingsRepository::class)->persist($settings);
}

it('still renders the slug-named part, for every site that predates this', function (): void {
    $author = chromeAuthor();
    chromePart($author, 'header', 'Legacy header');
    chromePage($author, 'legacy-chrome');

    // No role, no site default, no page choice — exactly the shape every
    // existing site is in. It must render as it always did.
    expect($this->get('/legacy-chrome')->assertOk()->getContent())
        ->toContain('Legacy header');
});

it('prefers the site default over the slug-named part', function (): void {
    $author = chromeAuthor();
    chromePart($author, 'header', 'Legacy header');
    $chosen = chromePart($author, 'brand-header', 'Site default header', ['role' => 'header']);
    setChromeDefault('header', (string) $chosen->getKey());

    chromePage($author, 'default-chrome');

    $html = (string) $this->get('/default-chrome')->assertOk()->getContent();

    expect($html)->toContain('Site default header')
        ->and(str_contains($html, 'Legacy header'))->toBeFalse();
});

it('lets one page override the site default', function (): void {
    $author = chromeAuthor();
    $default = chromePart($author, 'brand-header', 'Site default header', ['role' => 'header']);
    $special = chromePart($author, 'campaign-header', 'Campaign header', ['role' => 'header']);
    setChromeDefault('header', (string) $default->getKey());

    chromePage($author, 'plain-chrome');
    chromePage($author, 'special-chrome', ['header' => (string) $special->getKey()]);

    expect($this->get('/plain-chrome')->assertOk()->getContent())->toContain('Site default header');

    $html = (string) $this->get('/special-chrome')->assertOk()->getContent();
    expect($html)->toContain('Campaign header')
        ->and(str_contains($html, 'Site default header'))->toBeFalse();
});

it('refuses a part that does not say it is a header', function (): void {
    $author = chromeAuthor();
    chromePart($author, 'header', 'Legacy header');
    // A footer, named as this page's header. An id left behind after a part
    // was repurposed must not put a footer at the top of the page.
    $footer = chromePart($author, 'brand-footer', 'A footer', ['role' => 'footer']);

    chromePage($author, 'wrong-role', ['header' => (string) $footer->getKey()]);

    $html = (string) $this->get('/wrong-role')->assertOk()->getContent();

    expect(str_contains($html, 'A footer'))->toBeFalse();
    // Falls THROUGH to the next rung rather than rendering nothing.
    expect($html)->toContain('Legacy header');
});

it('falls through when the chosen part is unpublished', function (): void {
    $author = chromeAuthor();
    chromePart($author, 'header', 'Legacy header');
    $draft = chromePart($author, 'draft-header', 'Draft header', ['role' => 'header']);
    app(EntryManager::class)->unpublish($draft, actorId: $author->id);

    chromePage($author, 'unpublished-chrome', ['header' => (string) $draft->getKey()]);

    $html = (string) $this->get('/unpublished-chrome')->assertOk()->getContent();

    expect(str_contains($html, 'Draft header'))->toBeFalse()
        ->and($html)->toContain('Legacy header');
});

it('sticks a header that says it should, and only then', function (): void {
    $author = chromeAuthor();
    $sticky = chromePart($author, 'sticky-header', 'Sticky header', [
        'role' => 'header',
        'behaviour' => ['sticky' => true],
    ]);
    setChromeDefault('header', (string) $sticky->getKey());
    chromePage($author, 'sticky-chrome');

    $html = (string) $this->get('/sticky-chrome')->assertOk()->getContent();

    // The rule names the wrapper AND whatever element holds it: a theme
    // puts our HTML inside its own <header>, and an element can only stick
    // within its parent's box.
    expect($html)->toContain('magna-chrome--sticky')
        ->and($html)->toContain('position:sticky')
        ->and($html)->toContain(':has(>.magna-chrome--sticky)');

    // A page whose chrome does not stick pays nothing for the feature.
    setChromeDefault('header', null);
    chromePart($author, 'header', 'Plain header');
    chromePage($author, 'plain-sticky');

    expect(str_contains((string) $this->get('/plain-sticky')->getContent(), 'position:sticky'))
        ->toBeFalse();
});

it('keeps a page choice through the builder settings endpoint', function (): void {
    $author = chromeAuthor();
    $header = chromePart($author, 'brand-header', 'Chosen header', ['role' => 'header']);
    $page = chromePage($author, 'endpoint-chrome');

    $this->actingAs($author)->getJson(url('/pages-builder/'.$page->getKey()))->assertOk();
    $this->actingAs($author)->putJson(url('/pages-builder/'.$page->getKey().'/settings'), [
        'settings' => ['header' => (string) $header->getKey()],
    ])->assertOk();

    // A page may choose its header without ever touching its background.
    $stored = Entry::type('page')->find($page->getKey())?->getAttribute('page_settings');
    expect($stored['header'])->toBe((string) $header->getKey());

    auth()->logout();
    expect($this->get('/endpoint-chrome')->assertOk()->getContent())->toContain('Chosen header');
});

it('offers only parts meant for the role', function (): void {
    $author = chromeAuthor();
    chromePart($author, 'brand-header', 'H', ['role' => 'header']);
    chromePart($author, 'brand-footer', 'F', ['role' => 'footer']);
    chromePart($author, 'sidebar', 'S', ['role' => 'generic']);

    $resolver = app(TemplatePartResolver::class);

    expect(array_column($resolver->chromeChoices('header'), 'slug'))->toBe(['brand-header'])
        ->and(array_column($resolver->chromeChoices('footer'), 'slug'))->toBe(['brand-footer']);
});

it('reports a published part as published, and a draft as a draft', function (): void {
    $author = chromeAuthor();

    // `status` is cast to an enum. Comparing the raw attribute to a string
    // is always false, which reported every published part as a draft and
    // left it unselectable in both pickers — the feature looked broken
    // while the resolver underneath was working perfectly.
    $published = chromePart($author, 'live-header', 'Live', ['role' => 'header']);
    $draft = app(EntryManager::class)->create('pages_template', [
        'title' => 'Draft', 'slug' => 'draft-header', 'kind' => 'part',
        'role' => 'header', 'blocks_data' => [],
    ], $author->id);

    $choices = collect(app(TemplatePartResolver::class)->chromeChoices('header'))
        ->keyBy('slug');

    expect($choices['live-header']['published'])->toBeTrue()
        ->and($choices['draft-header']['published'])->toBeFalse()
        ->and($published->getKey())->not->toBe($draft->getKey());
});
