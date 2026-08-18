<?php

declare(strict_types=1);

/**
 * The Studio theme (themes/magna/theme-studio) + its reference addon
 * (magna/studio-pages-kit): second real theme proving the theme system is
 * portable, and the living example of the addon mechanic — the Pages
 * Loop block rendered native-to-Studio through extends + pairsWith.
 */

use Magna\Content\EntryManager;
use Magna\Pages\Menus\MenuManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function studioSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
    app(ThemeManager::class)->activate('magna/theme-studio');

    return User::factory()->create();
}

it('renders a landing page through the Studio shell with its tokens', function (): void {
    $author = studioSetup();
    $entryManager = app(EntryManager::class);

    $landing = $entryManager->create('page', [
        'title' => 'Studio Home', 'slug' => 'studio-home',
        'blocks_data' => [[
            'id' => 'sec-st', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-st', 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'blk-st-hero', 'block' => 'hero', 'settings' => [], 'data' => [
                        'layout' => 'split',
                        'headline' => 'Work that speaks',
                        'cta_primary_label' => 'See projects',
                        'cta_primary_url' => '/work',
                    ]],
                    ['id' => 'blk-st-faq', 'block' => 'faq', 'settings' => [], 'data' => [
                        'items' => [['question' => 'Remote?', 'answer' => 'Always.']],
                    ]],
                ],
            ]],
        ]],
    ], $author->id);
    $entryManager->publish($landing, actorId: $author->id);

    $html = (string) $this->get('/studio-home')->assertOk()->getContent();

    expect($html)->toContain('s-header')                       // Studio shell, not Launch's l-header
        ->and($html)->toContain('b-hero__rule')                // Studio hero variant
        ->and($html)->toContain('Work that speaks')
        ->and($html)->toContain('b-faq')
        ->and($html)->toContain('--color-primary:#f59e0b')     // Studio tokens flowed in
        ->and($html)->toContain('--max-width:1200px');
});

it('renders the Loop block through the paired addon as Studio cards', function (): void {
    $author = studioSetup();
    $entryManager = app(EntryManager::class);

    // A couple of published pages for the built-in pages.latest source.
    foreach ([['Alpha', 'alpha'], ['Beta', 'beta']] as [$title, $slug]) {
        $page = $entryManager->create('page', ['title' => $title, 'slug' => $slug, 'blocks_data' => []], $author->id);
        $entryManager->publish($page, actorId: $author->id);
    }

    $listing = $entryManager->create('page', [
        'title' => 'Latest', 'slug' => 'latest',
        'blocks_data' => [[
            'id' => 'sec-sl', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-sl', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-sl', 'block' => 'loop', 'settings' => [], 'data' => [
                    'heading' => 'Recent pages', 'source' => 'pages.latest', 'limit' => 5,
                ]]],
            ]],
        ]],
    ], $author->id);
    $entryManager->publish($listing, actorId: $author->id);

    $html = (string) $this->get('/latest')->assertOk()->getContent();

    // The addon's card view won over the plugin's default loop view...
    expect($html)->toContain('magna-loop--studio-cards')
        ->and($html)->toContain('Alpha')
        ->and($html)->toContain('href="/beta"')
        // ...and its additive token joined the theme's variables.
        ->and($html)->toContain('--color-loop-card:#292524');
});

it('neutralises a javascript: menu URL in the header nav', function (): void {
    $author = studioSetup();
    $entryManager = app(EntryManager::class);

    $page = $entryManager->create('page', ['title' => 'Nav Safe', 'slug' => 'nav-safe', 'blocks_data' => []], $author->id);
    $entryManager->publish($page, actorId: $author->id);

    app(MenuManager::class)->syncItems(
        app(MenuManager::class)->create('primary', 'Primary'),
        [
            ['label' => 'Fine', 'type' => 'url', 'url' => '/about'],
            ['label' => 'Evil', 'type' => 'url', 'url' => 'javascript:alert(1)'],
        ],
    );

    $html = (string) $this->get('/nav-safe')->assertOk()->getContent();

    expect($html)->toContain('href="/about"')
        ->and($html)->toContain('Evil')
        ->and($html)->not->toContain('javascript:alert');
});
