<?php

declare(strict_types=1);

/**
 * Themes v2 loader: the active theme's layout replaces the built-in shell,
 * its block views win over core defaults with per-block fallback, and its
 * tokens.json compiles to injection-filtered CSS custom properties.
 */

use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function themeRenderingSetup(bool $activateTheme = true): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    config()->set('magna.themes_path', base_path('tests/Fixtures/themes'));

    if ($activateTheme) {
        app(ThemeManager::class)->activate('testv/fixture');
    }

    return User::factory()->create();
}

function publishThemedPage(User $author): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Themed page',
        'slug' => 'themed-page',
        'blocks_data' => [[
            'id' => 'sec-1', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-1', 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'blk-h', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Styled headline']],
                    ['id' => 'blk-t', 'block' => 'text', 'settings' => [], 'data' => ['body' => '<p>Core-styled body</p>']],
                ],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('renders through the active theme layout with theme block views winning', function (): void {
    $author = themeRenderingSetup();
    publishThemedPage($author);

    $response = $this->get('/themed-page')->assertOk();

    $html = (string) $response->getContent();

    expect($html)->toContain('fixture-theme-layout')      // theme shell replaced built-in
        ->and($html)->toContain('fixture-theme-header')
        ->and($html)->toContain('fixture-theme-heading')  // theme's heading view won
        ->and($html)->toContain('Styled headline')
        ->and($html)->toContain('<p>Core-styled body</p>'); // text block fell back to core view
});

it('compiles theme tokens to filtered CSS custom properties', function (): void {
    $author = themeRenderingSetup();
    publishThemedPage($author);

    $html = (string) $this->get('/themed-page')->assertOk()->getContent();

    expect($html)->toContain('--color-primary:#e11d48')
        ->and($html)->toContain('--max-width:1200px')     // camelCase → kebab
        ->and($html)->toContain('--radius:0.75rem')
        // Injection-filtered values never reach the style element.
        ->and($html)->not->toContain('display:none')
        ->and($html)->not->toContain('evil.example');
});

it('falls back to the built-in shell when no theme is active', function (): void {
    $author = themeRenderingSetup(activateTheme: false);
    publishThemedPage($author);

    $html = (string) $this->get('/themed-page')->assertOk()->getContent();

    expect($html)->not->toContain('fixture-theme-layout')
        ->and($html)->not->toContain('fixture-theme-heading')
        ->and($html)->toContain('Styled headline');
});
