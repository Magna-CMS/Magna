<?php

declare(strict_types=1);

/**
 * Theme addons (Phase C, 04-THEMES-V2 §5): an addon styles its paired
 * plugin's blocks inside a host theme through the resolution chain
 * addon → theme → plugin default → core default. §C8 constraints: an
 * addon may only override views for blocks whose sourcePlugin its
 * pairsWith names — core blocks never; specific `extends` beats "*";
 * addon tokens are additive only; an addon can never be activated AS
 * the theme.
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Pages\Themes\ThemeTokens;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\InvalidThemeException;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function addonSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);
    app(ThemeManager::class)->activate('addonhost/base');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

function addonPage(User $author, string $slug): void
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Addon host page', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-ta-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-ta-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'blk-ta-nav-'.$slug, 'block' => 'nav', 'settings' => [], 'data' => ['menu' => 'primary']],
                    ['id' => 'blk-ta-h-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Real core heading']],
                ],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);
}

it('renders a paired plugin block through the addon view, specific extends first', function (): void {
    $author = addonSetup();
    addonPage($author, 'addon-page');

    $html = $this->get('/addon-page')->assertOk()->getContent();

    // The specific addon (extends addonhost/base) beats the "*" addon.
    expect($html)->toContain('SPECIFIC ADDON NAV')
        ->and($html)->not->toContain('STAR ADDON NAV');
});

it('never lets an addon override a core block view', function (): void {
    $author = addonSetup();
    addonPage($author, 'core-guard');

    $html = $this->get('/core-guard')->assertOk()->getContent();

    // The addon SHIPS views/blocks/heading.blade.php; resolution refuses
    // it because heading has no sourcePlugin (core block).
    expect($html)->toContain('Real core heading')
        ->and($html)->not->toContain('ADDON MUST NEVER RENDER CORE BLOCKS');
});

it('falls back to the star addon when no specific addon applies', function (): void {
    $author = addonSetup();

    // Deactivate the host theme: the specific addon (extends addonhost/base)
    // no longer applies, the "*" addon still does — but with NO active theme
    // the star addon still styles plugin blocks.
    app(ThemeManager::class)->deactivate();
    addonPage($author, 'star-page');

    $html = $this->get('/star-page')->assertOk()->getContent();

    expect($html)->toContain('STAR ADDON NAV')
        ->and($html)->not->toContain('SPECIFIC ADDON NAV');
});

it('merges addon tokens additively, never repainting the theme', function (): void {
    addonSetup();

    $variables = app(ThemeTokens::class)->themeVariables();

    // Theme's own value survives the addon's attempt to redefine it...
    expect($variables['--color-primary'])->toBe('#111111')
        // ...while the addon's genuinely new token lands.
        ->and($variables['--color-chat-bubble'])->toBe('#00ff00');
});

it('refuses to activate an addon as the theme', function (): void {
    addonSetup();

    expect(fn () => app(ThemeManager::class)->activate('addonvendor/pages-kit'))
        ->toThrow(InvalidThemeException::class, 'theme addon');
});
