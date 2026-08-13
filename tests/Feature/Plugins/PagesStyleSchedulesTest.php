<?php

declare(strict_types=1);

/**
 * Scheduled design changes (Phase D): a token set that takes over the
 * site's look between two moments. Only theme-declared variables may be
 * scheduled, the page cache expires at the boundary so a palette can
 * never be served late, and the site returns to its standing look when
 * the window closes.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Pages\Cache\PageCache;
use Magna\Pages\Themes\StyleSchedule;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function scheduleUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
    app(ThemeManager::class)->activate('magna/launch');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.design', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

function schedulePage(User $author, string $slug): void
{
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', ['title' => 'Styled', 'slug' => $slug, 'blocks_data' => []], $author->id);
    $manager->publish($entry, actorId: $author->id);
}

it('applies a running schedule, ignores undeclared tokens, and reverts after it ends', function (): void {
    $author = scheduleUser();
    schedulePage($author, 'scheduled-look');

    StyleSchedule::query()->create([
        'theme' => 'magna/launch',
        'label' => 'Summer sale',
        'tokens' => [
            '--color-primary' => '#ff6600',
            // Not declared by the theme: must never reach the page.
            '--color-invented' => '#123456',
        ],
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    $html = $this->get('/scheduled-look')->assertOk()->getContent();
    // The DECLARATION carries the scheduled value (the theme's own
    // stylesheet still names #2563eb as a var() fallback — that is the
    // fallback, not the token).
    expect($html)->toContain('--color-primary:#ff6600')
        ->and($html)->not->toContain('--color-primary:#2563eb')
        ->and($html)->not->toContain('--color-invented');

    // After the window closes the site is itself again.
    $this->travelTo(now()->addHours(2));
    app(PageCache::class)->flush();

    expect($this->get('/scheduled-look')->getContent())
        ->toContain('--color-primary:#2563eb')
        ->and($this->get('/scheduled-look')->getContent())->not->toContain('--color-primary:#ff6600');
});

it('shortens the cache TTL to the next scheduled boundary', function (): void {
    $author = scheduleUser();
    schedulePage($author, 'boundary-page');

    // A change that starts in ten minutes.
    StyleSchedule::query()->create([
        'theme' => 'magna/launch',
        'label' => 'Evening palette',
        'tokens' => ['--color-primary' => '#000000'],
        'starts_at' => now()->addMinutes(10),
    ]);

    $this->get('/boundary-page')->assertOk()->assertHeader('X-Magna-Cache', 'miss');

    $row = DB::table('pages_cache')
        ->where('url', '/boundary-page')
        ->first();

    // Expiry lands at the boundary, not at the hour-long default.
    $expiresAt = Carbon::parse((string) $row?->expires_at);
    expect($expiresAt->diffInMinutes(now()))->toBeLessThanOrEqual(10)
        ->and($expiresAt->isAfter(now()))->toBeTrue();
});

it('picks the latest started schedule when two overlap', function (): void {
    $author = scheduleUser();
    schedulePage($author, 'overlap-page');

    StyleSchedule::query()->create([
        'theme' => 'magna/launch', 'label' => 'First',
        'tokens' => ['--color-primary' => '#111111'],
        'starts_at' => now()->subHours(2),
    ]);
    StyleSchedule::query()->create([
        'theme' => 'magna/launch', 'label' => 'Correction',
        'tokens' => ['--color-primary' => '#222222'],
        'starts_at' => now()->subHour(),
    ]);

    // A correction posted later wins without deleting the first.
    expect($this->get('/overlap-page')->getContent())->toContain('--color-primary:#222222');
});
