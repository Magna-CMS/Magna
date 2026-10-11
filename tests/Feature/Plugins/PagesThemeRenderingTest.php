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
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

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

function publishTogglePage(User $author, int $instances = 1): Entry
{
    $manager = app(EntryManager::class);

    $blocks = [];
    for ($i = 0; $i < $instances; $i++) {
        $blocks[] = ['id' => 'blk-s'.$i, 'block' => 'scheme-toggle', 'settings' => [], 'data' => []];
    }

    $entry = $manager->create('page', [
        'title' => 'Toggle page',
        'slug' => 'toggle-page',
        'blocks_data' => [[
            'id' => 'sec-1', 'type' => 'section', 'settings' => [],
            'columns' => [['id' => 'col-1', 'span' => 12, 'settings' => [], 'blocks' => $blocks]],
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

it('keeps a block behaviour the theme overrode the markup of', function (): void {
    // The bug this is here for: scheme-toggle shipped its un-hide, click
    // handler and stored choice in an @once script INSIDE its view, and the
    // theme audit forbids a block view its own <script>. So a theme that
    // overrode the view purely to restyle the button lost the behaviour and
    // was not allowed to put it back - leaving a control that renders
    // `hidden` and is never un-hidden. Not a styling regression anybody
    // sees: a permanently invisible, dead button.
    $author = themeRenderingSetup();
    publishTogglePage($author);

    $html = (string) $this->get('/toggle-page')->assertOk()->getContent();

    // The theme's markup won, and core's default markup is gone.
    expect($html)->toContain('fixture-theme-toggle')
        ->and($html)->not->toContain('magna-scheme-toggle__button');

    // ...and the behaviour came anyway, because it no longer lives in the
    // view the theme replaced.
    expect($html)->toContain("setAttribute('data-theme'")
        ->and($html)->toContain('localStorage.setItem(KEY, next)')
        ->and($html)->toContain('button.hidden = false')
        ->and($html)->toContain('[data-magna-scheme-toggle]');

    // The theme's own override file carries none of that - so this passes
    // on account of the behaviour view and nothing else.
    $override = (string) file_get_contents(
        base_path('tests/Fixtures/themes/testv/fixture/views/blocks/scheme-toggle.blade.php'),
    );
    expect($override)->not->toContain('<script');
});

it('ships one copy of a block behaviour however many times the block appears', function (): void {
    // @once in the behaviour view, not per instance: two switches on a page
    // (a header one and a footer one) wire from the same script.
    $author = themeRenderingSetup();
    publishTogglePage($author, instances: 3);

    $html = (string) $this->get('/toggle-page')->assertOk()->getContent();

    expect(substr_count($html, 'fixture-theme-toggle'))->toBe(3)
        ->and(substr_count($html, "var KEY = 'magna-theme'"))->toBe(1);
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
