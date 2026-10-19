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

/*
 * resolve() is match() plus `{param}` capture — the half the blog's
 * `/blog/{slug}` post pages ride on. The precedence rule is pinned hardest:
 * an exact page always beats a parameterized sibling, whatever order the
 * plugins booted in.
 */

it('resolves a parameterized path and captures its segments', function (): void {
    $registry = new FrontendPageRegistry;
    $registry->register(frontendPage('blog/{slug}', 'blog.post'));

    $match = $registry->resolve('blog/hello-world');

    expect($match?->page->name)->toBe('blog.post')
        ->and($match?->params)->toBe(['slug' => 'hello-world'])
        ->and($registry->resolve('/blog/hello-world/')?->params)->toBe(['slug' => 'hello-world']);
});

it('refuses a parameterized match at the wrong depth or with an empty segment', function (): void {
    $registry = new FrontendPageRegistry;
    $registry->register(frontendPage('blog/{slug}', 'blog.post'));

    expect($registry->resolve('blog'))->toBeNull()
        ->and($registry->resolve('blog/a/b'))->toBeNull()
        ->and($registry->resolve('blog//'))->toBeNull();
});

it('prefers an exact page over a parameterized one, whatever the registration order', function (): void {
    $registry = new FrontendPageRegistry;
    $registry->register(frontendPage('blog/{slug}', 'blog.post'));
    $registry->register(frontendPage('blog/archive', 'blog.archive'));

    $match = $registry->resolve('blog/archive');

    expect($match?->page->name)->toBe('blog.archive')
        ->and($match?->params)->toBe([]);
});

it('captures several parameters, literal segments compared exactly', function (): void {
    $registry = new FrontendPageRegistry;
    $registry->register(frontendPage('blog/{year}/{slug}', 'blog.dated'));

    expect($registry->resolve('blog/2026/hello')?->params)->toBe(['year' => '2026', 'slug' => 'hello'])
        ->and($registry->resolve('news/2026/hello'))->toBeNull()
        // An empty middle segment never satisfies a token — `{year}` must
        // capture something, not vanish between two slashes.
        ->and($registry->resolve('blog//hello'))->toBeNull();
});

it('resolves an exact page with no params, so existing pages are untouched', function (): void {
    $registry = new FrontendPageRegistry;
    $registry->register(frontendPage('chat', 'chat.home'));

    $match = $registry->resolve('chat');

    expect($match?->page->name)->toBe('chat.home')
        ->and($match?->params)->toBe([])
        ->and($match?->page->hasParameters())->toBeFalse();
});
