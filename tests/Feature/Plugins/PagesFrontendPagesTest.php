<?php

declare(strict_types=1);

/**
 * Plugin frontend pages (Phase C, ProvidesFrontendPages): a plugin's page
 * mounts under the site router wrapped in the layout shell; a published
 * page entry at the same path always wins; declared auth requirements are
 * enforced before render (guest redirect / 403); menu items pointing at a
 * page the visitor may not open hide themselves; plugin pages never enter
 * the shared page cache.
 *
 * (Registry population goes through the same 5-line PluginContractWirer
 * pattern already exercised end-to-end by the data-source and dynamic-tag
 * suites; these tests register definitions directly.)
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Frontend\FrontendPage;
use Magna\Frontend\FrontendPageRegistry;
use Magna\Pages\Menus\MenuManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function frontendSetup(): void
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    view()->addNamespace('fpstub', dirname(__DIR__, 2).'/stubs/frontend');

    $registry = app(FrontendPageRegistry::class);
    $registry->register(new FrontendPage(
        path: 'chat', name: 'chat.home', title: 'Chat', view: 'fpstub::chat',
    ));
    $registry->register(new FrontendPage(
        path: 'board', name: 'board.home', title: 'Board', view: 'fpstub::board',
        mode: FrontendPage::MODE_APP, requiresAuth: true, permission: 'panel.access',
    ));
}

function frontendUser(string ...$permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant(...$permissions);
    $user->assignRole($role);

    return $user;
}

it('renders a plugin page inside the layout shell, uncached', function (): void {
    frontendSetup();

    $response = $this->get('/chat')->assertOk()
        ->assertHeader('X-Magna-Cache', 'bypass');

    $html = $response->getContent();
    expect($html)->toContain('Chat stub main content')
        ->and($html)->toContain('magna-frontend-page--content')
        // The shell is the site's: full document, page title in <title>.
        ->and($html)->toContain('<title>Chat')
        ->and($html)->toContain('<main');
});

it('lets a published page entry at the same path win over the plugin page', function (): void {
    frontendSetup();
    $author = frontendUser('panel.access', 'pages.content', 'pages.layout', 'pages.publish');

    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Real Chat Page', 'slug' => 'chat',
        'blocks_data' => [[
            'id' => 'sec-fp', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-fp', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-fp', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Owner content']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    $html = $this->get('/chat')->assertOk()->getContent();

    expect($html)->toContain('Owner content')
        ->and($html)->not->toContain('Chat stub main content');
});

it('sends guests to login for an auth-required page and refuses missing permission', function (): void {
    frontendSetup();

    // Guest: redirected, never rendered.
    $this->get('/board')->assertRedirect();

    // Authenticated but lacking the declared permission: refused.
    $this->actingAs(frontendUser('pages.content'))->get('/board')->assertForbidden();

    // Holding the permission: the app-mode page renders in the shell.
    $html = $this->actingAs(frontendUser('panel.access'))->get('/board')
        ->assertOk()->getContent();
    expect($html)->toContain('Board stub app mount')
        ->and($html)->toContain('magna-frontend-page--app');
});

it('shows plugin pages in menus and hides ones the visitor may not open', function (): void {
    frontendSetup();

    $manager = app(MenuManager::class);
    $menu = $manager->create('primary', 'Primary');
    $manager->syncItems($menu, [
        ['label' => '', 'type' => 'plugin', 'plugin_page' => 'chat.home'],
        ['label' => 'Team board', 'type' => 'plugin', 'plugin_page' => 'board.home'],
        ['label' => 'Gone', 'type' => 'plugin', 'plugin_page' => 'vanished.page'],
    ]);

    // Guest: public page shown (label falls back to the page title),
    // auth-gated and vanished pages hidden.
    $links = $manager->resolve('primary');
    expect(array_column($links, 'label'))->toBe(['Chat'])
        ->and($links[0]['url'])->toBe('/chat');

    // A visitor holding the permission sees the gated item too.
    $this->actingAs(frontendUser('panel.access'));
    $links = $manager->resolve('primary');
    expect(array_column($links, 'label'))->toBe(['Chat', 'Team board'])
        ->and($links[1]['url'])->toBe('/board');
});
