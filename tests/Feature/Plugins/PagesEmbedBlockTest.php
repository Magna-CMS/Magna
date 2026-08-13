<?php

declare(strict_types=1);

/**
 * The curated embed block (Phase C item 9): allowlisted providers render
 * privacy-friendly iframes whose src is REBUILT from constants plus the
 * extracted id — a pasted URL never reaches the page as-is, and anything
 * outside the allowlist renders a gap, never an iframe.
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function embedUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

function embedPage(User $author, string $slug, string $url): void
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Embed page', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-em-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-em-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-em-'.$slug, 'block' => 'embed', 'settings' => [], 'data' => [
                    'url' => $url, 'caption' => 'Watch this',
                ]]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);
}

it('renders allowlisted providers with rebuilt embed sources', function (): void {
    $author = embedUser();
    embedPage($author, 'yt-long', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    embedPage($author, 'yt-short', 'https://youtu.be/dQw4w9WgXcQ');
    embedPage($author, 'vimeo', 'https://vimeo.com/123456789');

    foreach (['yt-long', 'yt-short'] as $slug) {
        $html = $this->get('/'.$slug)->assertOk()->getContent();
        expect($html)->toContain('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
            // The pasted URL itself never renders.
            ->and($html)->not->toContain('youtube.com/watch');
    }

    $html = $this->get('/vimeo')->assertOk()->getContent();
    expect($html)->toContain('https://player.vimeo.com/video/123456789')
        ->and($html)->toContain('Watch this');
});

it('renders a gap for anything outside the allowlist', function (): void {
    $author = embedUser();

    // (A javascript: URL is refused even earlier — the link field's scheme
    // allowlist rejects it at save, so it cannot reach this resolver.)
    foreach ([
        'evil-host' => 'https://evil.example/watch?v=dQw4w9WgXcQ',
        'userinfo-trick' => 'https://www.youtube.com@evil.example/watch?v=dQw4w9WgXcQ',
        'vimeo-path-trick' => 'https://vimeo.com/../../evil',
    ] as $slug => $url) {
        embedPage($author, $slug, $url);

        $html = $this->get('/'.$slug)->assertOk()->getContent();
        expect($html)->not->toContain('<iframe')
            ->and($html)->not->toContain('evil.example');
    }
});
