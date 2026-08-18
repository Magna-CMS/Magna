<?php

declare(strict_types=1);

/**
 * The Launch default theme (themes/magna/launch): activates from the real
 * themes directory, wraps pages in its header/nav/footer shell, styles the
 * standard blocks, and carries its tokens into the page as CSS variables.
 */

use Magna\Content\EntryManager;
use Magna\Pages\Menus\MenuManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function launchSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
    app(ThemeManager::class)->activate('magna/launch');

    return User::factory()->create();
}

it('renders a landing page through the Launch shell with nav, hero, and tokens', function (): void {
    $author = launchSetup();
    $entryManager = app(EntryManager::class);

    $about = $entryManager->create('page', ['title' => 'About', 'slug' => 'about', 'blocks_data' => []], $author->id);
    $entryManager->publish($about, actorId: $author->id);

    app(MenuManager::class)->syncItems(
        app(MenuManager::class)->create('primary', 'Primary'),
        [['label' => 'About', 'type' => 'page', 'page_id' => (string) $about->getKey()]],
    );

    $landing = $entryManager->create('page', [
        'title' => 'Home',
        'slug' => 'home',
        'blocks_data' => [[
            'id' => 'sec-1', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-1', 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'blk-hero', 'block' => 'hero', 'settings' => [], 'data' => [
                        'layout' => 'centered',
                        'headline' => 'Build faster with Magna',
                        'subheadline' => 'The headless CMS that speaks Laravel.',
                        'cta_primary_label' => 'Get started',
                        'cta_primary_url' => '/about',
                    ]],
                    ['id' => 'blk-faq', 'block' => 'faq', 'settings' => [], 'data' => [
                        'items' => [['question' => 'Is it fast?', 'answer' => 'Yes.']],
                    ]],
                ],
            ]],
        ]],
    ], $author->id);
    $entryManager->publish($landing, actorId: $author->id);

    $html = (string) $this->get('/home')->assertOk()->getContent();

    expect($html)->toContain('l-header')                         // Launch shell
        ->and($html)->toContain('b-hero')                        // theme hero view won over core
        ->and($html)->toContain('Build faster with Magna')
        ->and($html)->toContain('btn btn--primary')
        ->and($html)->toContain('b-faq')                         // theme faq view
        ->and($html)->toContain('Is it fast?')
        ->and($html)->toContain('l-nav')                         // header menu rendered
        ->and($html)->toContain('href="/about"')
        ->and($html)->toContain('--color-primary:#2563eb')       // tokens flowed in
        ->and($html)->toContain('--max-width:1152px')
        ->and($html)->toContain('prefers-color-scheme: dark');   // dark mode shipped
});

it('keeps rendering unstyled-by-theme blocks through the core fallback', function (): void {
    $author = launchSetup();
    $entryManager = app(EntryManager::class);

    $page = $entryManager->create('page', [
        'title' => 'Fallback', 'slug' => 'fallback',
        'blocks_data' => [[
            'id' => 'sec-1', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-1', 'span' => 12, 'settings' => [],
                // Launch ships no divider view — must fall back to core's.
                'blocks' => [['id' => 'blk-div', 'block' => 'divider', 'settings' => [], 'data' => []]],
            ]],
        ]],
    ], $author->id);
    $entryManager->publish($page, actorId: $author->id);

    $this->get('/fallback')->assertOk()->assertSee('magna-block--divider', false);
});

it('neutralises a javascript: menu URL in the header nav', function (): void {
    $author = launchSetup();
    $entryManager = app(EntryManager::class);

    $page = $entryManager->create('page', ['title' => 'Nav Safe', 'slug' => 'nav-safe', 'blocks_data' => []], $author->id);
    $entryManager->publish($page, actorId: $author->id);

    app(MenuManager::class)->syncItems(
        app(MenuManager::class)->create('primary', 'Primary'),
        [
            ['label' => 'Fine', 'type' => 'url', 'url' => '/about'],
            ['label' => 'Evil', 'type' => 'url', 'url' => 'javascript:alert(1)'],
            ['label' => 'Sneaky', 'type' => 'url', 'url' => " java\tscript:alert(2)"],
        ],
    );

    $html = (string) $this->get('/nav-safe')->assertOk()->getContent();

    expect($html)->toContain('href="/about"')          // legitimate URL intact
        ->and($html)->toContain('Evil')                // item renders, defanged
        ->and($html)->not->toContain('javascript:alert')
        ->and($html)->not->toContain('script:alert');
});
