<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Magna\Auth\Role;
use Magna\Marketplace\Marketplace;
use Magna\Updater\UpdateCheckClient;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Cross-checking work already done (not by this session originally) wiring
// core/plugin update availability and Magna announcements into the panel
// bell via NotificationRecipients::notifyDashboard() — the same helper this
// session extracted while fixing the identical gap for Backup Manager.
// UpdateCheckClientTest.php covers the update_checks persistence; none of
// its existing tests asserted the bell notification or its dedup logic at
// all, so that path was genuinely unverified until now.

function updateCheckSuperAdmin(): User
{
    $role = Role::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function fakeUpdatesResponse(array $overrides = []): void
{
    Http::fake([
        Marketplace::API_BASE.'/updates' => Http::response(array_merge([
            'core' => ['latest_version' => '9.9.9', 'update_available' => true, 'changelog_url' => null],
            'plugins' => [],
            'notices' => [],
        ], $overrides)),
    ]);
}

it('posts a bell notification the first time a core update becomes available', function (): void {
    $admin = updateCheckSuperAdmin();
    fakeUpdatesResponse();

    app(UpdateCheckClient::class)->checkIn();

    $row = DB::table('notifications')->where('notifiable_id', $admin->id)->first();
    expect($row)->not->toBeNull();
    $data = json_decode((string) $row->data, true);
    expect($data['title'])->toBe('Core update available')
        ->and($data['format'])->toBe('filament');
});

it('does not re-notify on a repeat check-in with no change', function (): void {
    $admin = updateCheckSuperAdmin();
    fakeUpdatesResponse();

    app(UpdateCheckClient::class)->checkIn();
    app(UpdateCheckClient::class)->checkIn(); // identical state

    $count = DB::table('notifications')->where('notifiable_id', $admin->id)->count();
    expect($count)->toBe(1);
});

it('re-notifies when the latest_version changes on a later check-in', function (): void {
    $admin = updateCheckSuperAdmin();

    // Http::fake() merges/appends stubs across calls rather than replacing
    // them — the first-registered match for a URL wins on every subsequent
    // request, so a second Http::fake() call for the same endpoint is
    // silently ignored. Http::fakeSequence() is the correct tool for
    // "different response on each call to the same URL."
    Http::fakeSequence(Marketplace::API_BASE.'/updates')
        ->push(['core' => ['latest_version' => '9.9.9', 'update_available' => true, 'changelog_url' => null], 'plugins' => [], 'notices' => []])
        ->push(['core' => ['latest_version' => '9.9.10', 'update_available' => true, 'changelog_url' => null], 'plugins' => [], 'notices' => []]);

    app(UpdateCheckClient::class)->checkIn();
    app(UpdateCheckClient::class)->checkIn();

    $count = DB::table('notifications')->where('notifiable_id', $admin->id)->count();
    expect($count)->toBe(2);
});

it('posts a bell notification for a newly available plugin update', function (): void {
    $admin = updateCheckSuperAdmin();
    fakeUpdatesResponse([
        'core' => ['latest_version' => '1.3.0-beta', 'update_available' => false, 'changelog_url' => null],
        'plugins' => ['acme/forum' => ['latest_version' => '2.0.0', 'update_available' => true, 'changelog_url' => null]],
    ]);

    app(UpdateCheckClient::class)->checkIn();

    $row = DB::table('notifications')->where('notifiable_id', $admin->id)->first();
    expect($row)->not->toBeNull();
    $data = json_decode((string) $row->data, true);
    expect($data['title'])->toBe('Plugin update available')
        ->and($data['body'])->toContain('acme/forum');
});

it('posts a bell notification for a new Magna announcement and does not repeat it on re-sync', function (): void {
    $admin = updateCheckSuperAdmin();
    $notice = [
        'id' => 501,
        'category' => 'welcome',
        'title' => 'Welcome to Magna',
        'description' => 'Thanks for installing Magna CMS.',
    ];
    fakeUpdatesResponse([
        'core' => ['latest_version' => '1.3.0-beta', 'update_available' => false, 'changelog_url' => null],
        'notices' => [$notice],
    ]);

    app(UpdateCheckClient::class)->checkIn();
    app(UpdateCheckClient::class)->checkIn(); // same notice again, must not duplicate

    $matching = DB::table('notifications')
        ->where('notifiable_id', $admin->id)
        ->get()
        ->filter(fn ($row) => (json_decode((string) $row->data, true)['title'] ?? null) === 'Welcome message');

    expect($matching)->toHaveCount(1);
});

it('never notifies a non-super-admin about updates or announcements', function (): void {
    $regularRole = Role::factory()->create(['is_super_admin' => false]);
    $regularUser = User::factory()->create();
    $regularUser->assignRole($regularRole);

    fakeUpdatesResponse();
    app(UpdateCheckClient::class)->checkIn();

    expect(DB::table('notifications')->where('notifiable_id', $regularUser->id)->exists())->toBeFalse();
});
