<?php

declare(strict_types=1);

/**
 * Display conditions (Phase C item 2) under the §C9 cacheability contract:
 * a condition declares its cache behavior alongside its truth. Auth rules
 * are cache-safe (the shared cache only stores guest renders), schedules
 * shorten the cache to their next boundary, unknown rules hide AND make
 * the page uncacheable, and the builder canvas shows everything.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function conditionsUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.design', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/** @param array<mixed> $conditions */
function conditionedPage(User $author, string $slug, array $conditions): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Conditioned', 'slug' => $slug,
        'blocks_data' => [
            [
                'id' => 'sec-open-'.$slug, 'type' => 'section', 'settings' => [],
                'columns' => [[
                    'id' => 'col-open-'.$slug, 'span' => 12, 'settings' => [],
                    'blocks' => [['id' => 'blk-open-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Always shown']]],
                ]],
            ],
            [
                'id' => 'sec-cond-'.$slug, 'type' => 'section',
                'settings' => ['conditions' => $conditions],
                'columns' => [[
                    'id' => 'col-cond-'.$slug, 'span' => 12, 'settings' => [],
                    'blocks' => [['id' => 'blk-cond-'.$slug, 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Members only']]],
                ]],
            ],
        ],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('applies motion presets as classes with a reduced-motion escape hatch', function (): void {
    $author = conditionsUser();
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Moving', 'slug' => 'moving',
        'blocks_data' => [[
            'id' => 'sec-motion', 'type' => 'section', 'settings' => ['motion' => 'rise'],
            'columns' => [[
                'id' => 'col-motion', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-motion', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Rises']]],
            ]],
        ], [
            'id' => 'sec-motion-bogus', 'type' => 'section', 'settings' => ['motion' => 'spin-violently'],
            'columns' => [[
                'id' => 'col-motion-b', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-motion-b', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Static']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    $html = $this->get('/moving')->assertOk()->getContent();

    expect($html)->toContain('magna-motion--rise')
        // Unknown presets render nothing — settings are editor input.
        ->and($html)->not->toContain('spin-violently')
        // The CSS ships with the page and respects reduced motion.
        ->and($html)->toContain('@keyframes magna-motion-rise')
        ->and($html)->toContain('prefers-reduced-motion: reduce');
});

it('renders validated custom CSS declarations and drops everything dangerous', function (): void {
    $author = conditionsUser();
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Styled', 'slug' => 'custom-styled',
        'blocks_data' => [[
            'id' => 'sec-css', 'type' => 'section',
            'settings' => ['customCss' => implode(';', [
                'margin-top: 4rem',
                'color: var(--color-primary)',
                'background: url(https://evil.example/x)',   // function: dropped
                'behavior: expression(alert(1))',            // function: dropped
                'width: calc(100% - 2rem)',                  // function: dropped (strict v1)
                'foo}: bar',                                 // malformed property
                'content: "</style><script>"',               // quotes/angles: dropped
            ])],
            'columns' => [[
                'id' => 'col-css', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-css', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Styled section']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    $html = $this->get('/custom-styled')->assertOk()->getContent();

    expect($html)->toContain('margin-top:4rem')
        ->and($html)->toContain('color:var(--color-primary)')
        ->and($html)->not->toContain('evil.example')
        ->and($html)->not->toContain('expression')
        ->and($html)->not->toContain('calc(')
        ->and($html)->not->toContain('<script>');
});

it('shows auth-conditioned sections to the right audience and stays cacheable', function (): void {
    $author = conditionsUser();
    conditionedPage($author, 'members', [['type' => 'auth', 'show' => 'authenticated']]);

    // Guest: hidden — and the guest render CACHES (miss, not bypass),
    // because the shared cache only ever holds the guest variant.
    $this->get('/members')->assertOk()
        ->assertSee('Always shown')->assertDontSee('Members only')
        ->assertHeader('X-Magna-Cache', 'miss');

    // Authenticated: shown, served live (per-user requests bypass cache).
    $this->actingAs($author)->get('/members')->assertOk()
        ->assertSee('Members only')
        ->assertHeader('X-Magna-Cache', 'bypass');

    // The cached guest copy must NOT contain the members section.
    expect(DB::table('pages_cache')->where('url', '/members')->value('body'))
        ->toContain('Always shown')->not->toContain('Members only');
});

it('honors schedule windows and expires the cache at the next boundary', function (): void {
    Carbon::setTestNow('2026-08-11 12:00:00');
    $author = conditionsUser();

    conditionedPage($author, 'timed', [[
        'type' => 'schedule',
        'from' => '2026-08-11 00:00:00',
        'until' => '2026-08-11 12:30:00',
    ]]);

    // Inside the window: shown, cached — but only until the boundary.
    $this->get('/timed')->assertOk()->assertSee('Members only');

    $expiresAt = DB::table('pages_cache')->where('url', '/timed')->value('expires_at');
    expect(Carbon::parse(is_string($expiresAt) ? $expiresAt : ''))->toEqual(Carbon::parse('2026-08-11 12:30:00'));

    // Past the boundary the stale row self-expires and the fresh render
    // hides the section.
    Carbon::setTestNow('2026-08-11 12:31:00');
    $this->get('/timed')->assertOk()->assertDontSee('Members only');

    Carbon::setTestNow();
});

it('fails closed on unknown condition types: hidden and uncacheable', function (): void {
    $author = conditionsUser();
    conditionedPage($author, 'exotic', [['type' => 'geo', 'country' => 'IS']]);

    // Hidden (a rule this install cannot evaluate must not leak content)
    // and the page never enters the shared cache (its cache behavior
    // cannot be promised either).
    $this->get('/exotic')->assertOk()
        ->assertDontSee('Members only')
        ->assertHeader('X-Magna-Cache', 'bypass');

    expect(DB::table('pages_cache')->where('url', '/exotic')->exists())->toBeFalse();
});

it('shows everything in the builder canvas — you cannot edit what you cannot see', function (): void {
    $author = conditionsUser();
    $page = conditionedPage($author, 'canvas-cond', [['type' => 'auth', 'show' => 'guests']]);

    // As an AUTHENTICATED editor the section would hide publicly, but the
    // canvas renders it anyway.
    $canvas = $this->actingAs($author)
        ->get(url('/pages-builder/'.$page->getKey().'/canvas'))
        ->assertOk()->getContent();

    expect($canvas)->toContain('Members only');
});
