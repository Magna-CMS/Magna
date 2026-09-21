<?php

declare(strict_types=1);

use Magna\Frontend\FrontendPage;
use Magna\Frontend\FrontendPageRegistry;

/*
 * The registry is the router's source of truth for plugin frontend pages
 * and had no tests. Two behaviours matter enough to pin: match() decides
 * which plugin page answers a public URL (its normalisation must agree
 * with how paths arrive from the router), and register() is documented
 * last-write-wins by name — silent, so this test is what makes a future
 * change to that trade-off a conscious one.
 */

function frontendPage(string $path, string $name): FrontendPage
{
    return new FrontendPage(path: $path, name: $name, title: ucfirst($name), view: 'x::y');
}

it('matches a page by its site-relative path, however the slashes arrive', function (): void {
    $registry = new FrontendPageRegistry;
    $registry->register(frontendPage('chat', 'chat.home'));
    $registry->register(frontendPage('chat/settings', 'chat.settings'));

    expect($registry->match('chat')?->name)->toBe('chat.home')
        ->and($registry->match('/chat/')?->name)->toBe('chat.home')
        ->and($registry->match('chat/settings')?->name)->toBe('chat.settings')
        ->and($registry->match('nope'))->toBeNull();
});

it('returns pages by name and lists them all', function (): void {
    $registry = new FrontendPageRegistry;
    $registry->register(frontendPage('chat', 'chat.home'));

    expect($registry->get('chat.home')?->title)->toBe('Chat.home')
        ->and($registry->get('missing'))->toBeNull()
        ->and(array_keys($registry->all()))->toBe(['chat.home']);
});

it('replaces a page registered under the same name, last write wins', function (): void {
    $registry = new FrontendPageRegistry;
    $registry->register(frontendPage('chat', 'chat.home'));
    $registry->register(frontendPage('chat-v2', 'chat.home'));

    // Documented trade-off: two plugins claiming one name is last-boot-wins,
    // silently. Pinned so changing that (e.g. to a loud refusal) is done on
    // purpose, not by accident.
    expect($registry->all())->toHaveCount(1)
        ->and($registry->get('chat.home')?->path)->toBe('chat-v2');
});
