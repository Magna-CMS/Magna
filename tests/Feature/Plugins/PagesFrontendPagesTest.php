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
 * Parameterized pages (`blog/{slug}`) hand their captures to a data
 * provider AFTER the auth checks; the provider's result feeds the view,
 * <title> and meta description, and a null result falls through to the
 * redirect table and the themed 404 like any dead path.
 *
 * (Registry population goes through the same 5-line PluginContractWirer
 * pattern already exercised end-to-end by the data-source and dynamic-tag
 * suites; these tests register definitions directly.)
 */

use Illuminate\Support\Facades\Log;
use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Frontend\FrontendPage;
use Magna\Frontend\FrontendPageDataProvider;
use Magna\Frontend\FrontendPageRegistry;
use Magna\Frontend\FrontendPageResult;
use Magna\Pages\Menus\MenuItemSources;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\Routing\PageRedirect;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

/** Serves one known post; counts every call so tests can prove it never ran. */
final class FixtureFrontendPostProvider implements FrontendPageDataProvider
{
    public static int $calls = 0;

    public function resolve(array $params): ?FrontendPageResult
    {
        self::$calls++;

        if (($params['slug'] ?? null) !== 'hello') {
            return null;
        }

        return new FrontendPageResult(
            data: ['postTitle' => 'Hello, world', 'body' => 'Provider-fed body text'],
            title: 'Hello post',
            metaDescription: 'A greeting from the provider',
        );
    }
}

/** The plain pages plus a parameterized post page backed by the fixture provider. */
function frontendPostSetup(): void
{
    frontendSetup();
    FixtureFrontendPostProvider::$calls = 0;

    app(FrontendPageRegistry::class)->register(new FrontendPage(
        path: 'blog/{slug}', name: 'blog.post', title: 'Post', view: 'fpstub::post',
        controller: FixtureFrontendPostProvider::class,
    ));
}

function frontendSetup(): void
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

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

it('renders a parameterized page with its provider data inside the layout shell, uncached', function (): void {
    frontendPostSetup();

    $html = $this->get('/blog/hello')->assertOk()
        ->assertHeader('X-Magna-Cache', 'bypass')
        ->getContent();

    expect($html)->toContain('<article class="stub-post"><h1>Hello, world</h1><p>Provider-fed body text</p></article>')
        ->and($html)->toContain('magna-frontend-page--content')
        ->and($html)->toContain('<main')
        ->and(FixtureFrontendPostProvider::$calls)->toBe(1);
});

it('puts the provider title in <title> and its description in the layout payload', function (): void {
    frontendPostSetup();

    $html = $this->get('/blog/hello')->assertOk()->getContent();

    expect($html)->toContain('<title>Hello post</title>')
        ->and($html)->not->toContain('<title>Post</title>')
        ->and($html)->toContain('<meta name="description" content="A greeting from the provider">');

    // A page without a provider keeps its own title and prints no description.
    $plain = $this->get('/chat')->assertOk()->getContent();
    expect($plain)->toContain('<title>Chat')
        ->and($plain)->not->toContain('<meta name="description"');
});

it('falls through to the redirect table and the themed 404 when the provider finds nothing', function (): void {
    frontendPostSetup();

    $this->get('/blog/missing')->assertNotFound()->assertSee('Page not found');
    expect(FixtureFrontendPostProvider::$calls)->toBe(1);

    PageRedirect::query()->create([
        'source_path' => 'blog/old-hello',
        'target_path' => 'blog/hello',
        'status' => 301,
        'automatic' => false,
    ]);

    $this->get('/blog/old-hello')
        ->assertStatus(301)
        ->assertHeader('Location', url('/blog/hello'));
});

it('sends guests to login for an auth-required parameterized page without running the provider', function (): void {
    frontendPostSetup();
    app(FrontendPageRegistry::class)->register(new FrontendPage(
        path: 'members/{slug}', name: 'members.post', title: 'Members', view: 'fpstub::post',
        requiresAuth: true, controller: FixtureFrontendPostProvider::class,
    ));

    $this->get('/members/hello')->assertRedirect();
    expect(FixtureFrontendPostProvider::$calls)->toBe(0);

    $this->actingAs(frontendUser('pages.content'))->get('/members/hello')
        ->assertOk()->assertSee('Provider-fed body text');
    expect(FixtureFrontendPostProvider::$calls)->toBe(1);
});

it('lets a published page entry at the literal path outrank the parameterized page', function (): void {
    frontendPostSetup();
    $author = frontendUser('panel.access', 'pages.content', 'pages.layout', 'pages.publish');

    $manager = app(EntryManager::class);
    $page = function (string $title, string $slug, ?string $parentId = null) use ($manager, $author) {
        $entry = $manager->create('page', [
            'title' => $title, 'slug' => $slug, 'parent_id' => $parentId,
            'blocks_data' => [[
                'id' => 'sec-'.$slug, 'type' => 'section', 'settings' => [],
                'columns' => [[
                    'id' => 'col-'.$slug, 'span' => 12, 'settings' => [],
                    'blocks' => [['id' => 'blk-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => $title]]],
                ]],
            ]],
        ], $author->id);

        return $manager->publish($entry, actorId: $author->id);
    };
    $blog = $page('Blog', 'blog');
    $page('Owner hello', 'hello', (string) $blog->getKey());

    $html = $this->get('/blog/hello')->assertOk()->getContent();

    expect($html)->toContain('Owner hello')
        ->and($html)->not->toContain('Provider-fed body text')
        ->and(FixtureFrontendPostProvider::$calls)->toBe(0);
});

it('lets an exact plugin page outrank a parameterized one of the same shape', function (): void {
    frontendPostSetup();
    app(FrontendPageRegistry::class)->register(new FrontendPage(
        path: 'blog/chat', name: 'blog.chat', title: 'Blog chat', view: 'fpstub::chat',
    ));

    $this->get('/blog/chat')->assertOk()->assertSee('Chat stub main content');
    expect(FixtureFrontendPostProvider::$calls)->toBe(0);
});

it('degrades a page whose controller is not a data provider to a logged 404', function (): void {
    frontendPostSetup();
    app(FrontendPageRegistry::class)->register(new FrontendPage(
        path: 'broken/{slug}', name: 'broken.post', title: 'Broken', view: 'fpstub::post',
        controller: stdClass::class,
    ));
    Log::spy();

    $this->get('/broken/hello')->assertNotFound();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => ($context['page'] ?? null) === 'broken.post')
        ->once();
});

it('keeps hidden and parameterized pages out of the menu picker and menus', function (): void {
    frontendPostSetup();
    app(FrontendPageRegistry::class)->register(new FrontendPage(
        path: 'secret', name: 'secret.page', title: 'Secret', view: 'fpstub::chat', menuVisible: false,
    ));

    expect(array_column(app(MenuItemSources::class)->pluginPages(), 'id'))
        ->toBe(['board.home', 'chat.home']);

    // An item saved against a parameterized page has no address to link.
    $manager = app(MenuManager::class);
    $menu = $manager->create('primary', 'Primary');
    $manager->syncItems($menu, [
        ['label' => 'Chat', 'type' => 'plugin', 'plugin_page' => 'chat.home'],
        ['label' => 'A post', 'type' => 'plugin', 'plugin_page' => 'blog.post'],
    ]);

    expect(array_column($manager->resolve('primary'), 'label'))->toBe(['Chat']);
});
