<?php

declare(strict_types=1);

/**
 * PHASE C EXIT TEST (00-VISION §5, the chat scenario): a third-party
 * plugin lights up the entire builder surface using ONLY the documented
 * contracts — a block, a data source, a dynamic tag, a frontend page —
 * and one page composes all of it: the plugin's block, a Loop over its
 * data, its live number bound into a heading, its screen in the menu.
 * Then the escape hatch: the same document consumed headless over the
 * delivery API with every dynamic value materialized.
 */

use Magna\Auth\Role;
use Magna\Blocks\BlockDefinition;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\DataSources\DataSource;
use Magna\Blocks\DataSources\DataSourceRegistry;
use Magna\Blocks\DynamicTags\DynamicTag;
use Magna\Blocks\DynamicTags\DynamicTagRegistry;
use Magna\Content\EntryManager;
use Magna\Frontend\FrontendPage;
use Magna\Frontend\FrontendPageRegistry;
use Magna\Pages\Menus\MenuManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

// ── The "chat plugin", spelled out as its contract contributions ─────────────

final class ChatConversationsSource implements DataSource
{
    public function handle(): string
    {
        return 'chat.conversations';
    }

    public function label(): string
    {
        return 'Recent conversations';
    }

    public function fetch(array $config): array
    {
        return [
            ['title' => 'Support: onboarding', 'url' => '/chat/1', 'date' => '2026-08-11'],
            ['title' => 'Sales: enterprise plan', 'url' => '/chat/2', 'date' => '2026-08-10'],
        ];
    }
}

final class ChatOnlineCountTag implements DynamicTag
{
    public function handle(): string
    {
        return 'chat.online_count';
    }

    public function label(): string
    {
        return 'Agents online';
    }

    public function cacheability(): string
    {
        return self::CACHE_PAGE;
    }

    public function resolve(): string
    {
        return '4';
    }
}

function exitTestSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    // What the chat plugin's entry class would register through
    // RegistersBlocks / RegistersDataSources / RegistersDynamicTags /
    // ProvidesFrontendPages — the same registries the wirer fills.
    app(BlockRegistry::class)->register(
        BlockDefinition::fromArray([
            'handle' => 'chat-window',
            'label' => 'Chat window',
            'category' => 'chat',
            'fields' => [['handle' => 'greeting', 'type' => 'text', 'label' => 'Greeting']],
        ])->withSourcePlugin('acme/chat'),
    );
    app(DataSourceRegistry::class)->register(new ChatConversationsSource);
    app(DynamicTagRegistry::class)->register(new ChatOnlineCountTag);

    view()->addNamespace('chatstub', dirname(__DIR__, 2).'/stubs/frontend');
    app(FrontendPageRegistry::class)->register(new FrontendPage(
        path: 'chat', name: 'chat.home', title: 'Chat', view: 'chatstub::chat',
    ));

    // The chat block's view, mounted the way a plugin's views are.
    view()->addNamespace('magna', dirname(__DIR__, 2).'/stubs/chat-views');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.design', 'pages.publish', 'pages.settings');
    $user->assignRole($role);

    return $user;
}

it('composes every plugin contribution on one page and serves it', function (): void {
    $author = exitTestSetup();
    $entries = app(EntryManager::class);

    // The site owner builds the support page: the plugin's own block, a
    // Loop over its conversations, and its live agent count bound inline.
    $page = $entries->create('page', [
        'title' => 'Support', 'slug' => 'support',
        'blocks_data' => [[
            'id' => 'sec-exit', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-exit', 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'blk-exit-h', 'block' => 'heading', 'settings' => [], 'data' => [
                        'text' => '{tag:chat.online_count} agents online now',
                    ]],
                    ['id' => 'blk-exit-chat', 'block' => 'chat-window', 'settings' => [], 'data' => [
                        'greeting' => 'How can we help?',
                    ]],
                    ['id' => 'blk-exit-loop', 'block' => 'loop', 'settings' => [], 'data' => [
                        'heading' => 'Recent conversations', 'source' => 'chat.conversations', 'limit' => 5,
                    ]],
                ],
            ]],
        ]],
    ], $author->id);
    $entries->publish($page, actorId: $author->id);

    // The plugin's screen joins the menu beside the built page.
    $menus = app(MenuManager::class);
    $menus->syncItems($menus->create('primary', 'Primary'), [
        ['label' => '', 'type' => 'plugin', 'plugin_page' => 'chat.home'],
        ['label' => 'Support', 'type' => 'page', 'page_id' => (string) $page->getKey()],
    ]);

    $html = $this->get('/support')->assertOk()->getContent();

    expect($html)->toContain('4 agents online now')            // inline tag resolved
        ->and($html)->toContain('How can we help?')            // plugin block rendered
        ->and($html)->toContain('chat-window-stub')            // through ITS view
        ->and($html)->toContain('Support: onboarding')         // loop over plugin data
        ->and($html)->toContain('href="/chat/1"');

    // The plugin's frontend page serves inside the site shell...
    expect($this->get('/chat')->assertOk()->getContent())
        ->toContain('Chat stub main content');

    // ...and the menu carries both the built page and the plugin page.
    $links = app(MenuManager::class)->resolve('primary');
    expect(array_column($links, 'url'))->toContain('/chat')
        ->and(array_column($links, 'label'))->toContain('Chat')
        ->and(array_column($links, 'label'))->toContain('Support');
});

it('serves the same composition headless with dynamic values materialized', function (): void {
    $author = exitTestSetup();
    $entries = app(EntryManager::class);

    $page = $entries->create('page', [
        'title' => 'Headless support', 'slug' => 'headless-support',
        'blocks_data' => [[
            'id' => 'sec-hx', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-hx', 'span' => 12, 'settings' => [],
                'blocks' => [
                    ['id' => 'blk-hx-loop', 'block' => 'loop', 'settings' => [], 'data' => [
                        'source' => 'chat.conversations', 'limit' => 5,
                    ]],
                ],
            ]],
        ]],
    ], $author->id);
    $entries->publish($page, actorId: $author->id);

    $tokenResult = $author->createToken('exit-delivery', ['delivery'], now()->addDay());
    $tokenResult->accessToken->forceFill(['scope' => 'delivery'])->save();

    $body = $this->getJson(
        '/api/v1/content/page/'.$page->getKey().'?resolve=1',
        ['Authorization' => 'Bearer '.$tokenResult->plainTextToken],
    )->assertOk()->json();

    // The headless consumer gets the SAME resolved data the renderer used
    // — plugin items materialized, no PHP registry needed client-side.
    $loopBlock = $body['data']['blocks_data'][0]['columns'][0]['blocks'][0];
    expect($loopBlock['_resolved']['items'][0]['title'])->toBe('Support: onboarding')
        ->and($loopBlock['_resolved']['items'][0]['url'])->toBe('/chat/1');
});
