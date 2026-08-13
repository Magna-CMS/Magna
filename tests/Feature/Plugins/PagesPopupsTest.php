<?php

declare(strict_types=1);

/**
 * Popup/announcement documents (Phase C item 1): pages_template kind=popup
 * renders site-wide as a dismissible overlay once published; §C9 conditions
 * inside it gate audience AND fold into the page-cache verdict; drafts and
 * fully conditioned-away popups emit nothing; the builder canvas never
 * shows popups over the document being edited.
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Pages\Render\PageRenderer;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function popupsUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/** @param list<array<string, mixed>> $conditions */
function popupDoc(User $author, string $slug, string $text, array $conditions = [], bool $publish = true): void
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('pages_template', [
        'title' => ucfirst($slug), 'slug' => $slug, 'kind' => 'popup',
        'blocks_data' => [[
            'id' => 'sec-pp-'.$slug, 'type' => 'section',
            'settings' => $conditions === [] ? [] : ['conditions' => $conditions],
            'columns' => [[
                'id' => 'col-pp-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-pp-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => $text]]],
            ]],
        ]],
    ], $author->id);

    if ($publish) {
        $manager->publish($entry, actorId: $author->id);
    }
}

function popupHostPage(User $author, string $slug): void
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Host', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-ph-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-ph-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-ph-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Host body']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);
}

it('renders published popups site-wide as dismissible overlays, drafts never', function (): void {
    $author = popupsUser();
    popupDoc($author, 'sale', 'Summer sale is on');
    popupDoc($author, 'secret', 'Unfinished popup', publish: false);
    popupHostPage($author, 'popup-host');

    $html = $this->get('/popup-host')->assertOk()->getContent();

    expect($html)->toContain('data-magna-popup="sale"')
        ->and($html)->toContain('Summer sale is on')
        ->and($html)->toContain('data-magna-popup-close')
        // Dismissal script ships with the popup.
        ->and($html)->toContain('magna-popup-dismissed:')
        ->and($html)->not->toContain('Unfinished popup');
});

it('folds popup conditions into the page cache verdict', function (): void {
    $author = popupsUser();
    popupHostPage($author, 'cache-host');

    // A plain popup keeps the page cacheable.
    popupDoc($author, 'plain', 'Welcome note');
    $this->get('/cache-host')->assertOk()->assertHeader('X-Magna-Cache', 'miss');
    $this->get('/cache-host')->assertOk()->assertHeader('X-Magna-Cache', 'hit');

    // A popup carrying a rule we cannot evaluate fails closed for the
    // WHOLE page: unknown rules are uncacheable by §C9.
    popupDoc($author, 'mystery', 'From the future', [['type' => 'plugin-rule-from-the-future']]);
    $this->get('/cache-host')->assertOk()->assertHeader('X-Magna-Cache', 'bypass');
});

it('shows an audience-conditioned popup only to its audience', function (): void {
    $author = popupsUser();
    popupDoc($author, 'members', 'Members news', [['type' => 'auth', 'show' => 'authenticated']]);
    popupHostPage($author, 'audience-host');

    // Guest render: the popup's only section is hidden, so no overlay at all.
    expect($this->get('/audience-host')->assertOk()->getContent())
        ->not->toContain('data-magna-popup="members"');

    // Logged-in: overlay present.
    expect($this->actingAs($author)->get('/audience-host')->assertOk()->getContent())
        ->toContain('Members news');
});

it('targets popups by path server-side and carries frequency rules to the client', function (): void {
    $author = popupsUser();
    $manager = app(EntryManager::class);

    // A popup limited to /pricing and its children, excluding /pricing/faq.
    $entry = $manager->create('pages_template', [
        'title' => 'Pricing nudge', 'slug' => 'pricing-nudge', 'kind' => 'popup',
        'blocks_data' => [[
            'id' => 'sec-target', 'type' => 'section',
            'settings' => ['popup' => [
                'paths' => ['/pricing', '/pricing-*'],
                'exclude' => ['/pricing-faq'],
                'frequency' => 'session',
                'delay' => 5,
                'scroll' => 40,
            ]],
            'columns' => [[
                'id' => 'col-target', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-target', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Special offer']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    foreach (['pricing', 'pricing-teams', 'pricing-faq', 'about-targeting'] as $slug) {
        popupHostPage($author, $slug);
    }

    // On target: rendered, with the client-side rules as data.
    $html = $this->get('/pricing')->assertOk()->getContent();
    expect($html)->toContain('Special offer')
        ->and($html)->toContain('data-magna-frequency="session"')
        ->and($html)->toContain('data-magna-delay="5"')
        ->and($html)->toContain('data-magna-scroll="40"');

    // Glob sibling matches; the excluded one and an unrelated page do not.
    expect($this->get('/pricing-teams')->getContent())->toContain('Special offer')
        ->and($this->get('/pricing-faq')->getContent())->not->toContain('Special offer')
        ->and($this->get('/about-targeting')->getContent())->not->toContain('Special offer');
});

it('never renders popups over the builder canvas', function (): void {
    $author = popupsUser();
    popupDoc($author, 'sale', 'Summer sale is on');

    $html = app(PageRenderer::class)->renderDocument([], 'Editing', builderMode: true);

    expect($html)->not->toContain('data-magna-popup');
});
