<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Magna\Admin\Pages\SystemInfoPage;
use Magna\Auth\Role;
use Magna\MagnaServiceProvider;
use Magna\Updater\CoreUpdateJob;
use Magna\Updater\CoreUpdateProgress;
use Magna\Updater\CoreUpdater;
use Magna\Updater\CoreUpdateStarter;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\PendingCoreUpdate;
use Magna\Updater\UpdateCheck;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    CoreUpdateProgress::forget();
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

function updaterSuperAdmin(): User
{
    $role = Role::factory()->create([
        'handle' => 'super_admin',
        'name' => 'Super Admin',
        'is_super_admin' => true,
    ]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

function pendingUpdate(): PendingCoreUpdate
{
    // Deliberately not a valid sha256, so apply() fails closed at its first
    // guard: these tests are about who runs the apply, not what it downloads.
    return new PendingCoreUpdate(
        version: '9.9.9',
        zipUrl: 'https://github.com/magna-cms/magna/archive/v9.9.9.zip',
        expectedSha256: 'not-a-real-checksum',
    );
}

// The whole point of the fix: on a QUEUE_CONNECTION=database install with no
// worker running, the job sat in the jobs table and the panel showed
// "Starting…" forever, because apply() had never been entered and nothing had
// written a progress entry at all.
it('records the release and a queued state before dispatching', function (): void {
    Queue::fake();

    app(CoreUpdateStarter::class)->start(pendingUpdate());

    Queue::assertPushed(CoreUpdateJob::class);

    $progress = CoreUpdater::progress();
    expect($progress['state'])->toBe(CoreUpdateState::Queued->value);
    expect($progress['message'])->toContain('9.9.9');
    expect($progress['percent'])->toBe(1);
    expect(CoreUpdateProgress::pending()?->version)->toBe('9.9.9');
});

// The button itself, not just the starter: its closure resolves CoreUpdateStarter
// out of the container, so a wrong signature here would only show up in the panel.
it('records the queued state when the Update Now action is used', function (): void {
    Queue::fake();
    $this->actingAs(updaterSuperAdmin());

    UpdateCheck::query()->create([
        'type' => 'core',
        'current_version' => '1.0.0',
        'latest_version' => '9.9.9',
        'download_url' => 'https://github.com/magna-cms/magna/archive/v9.9.9.zip',
        'download_sha256' => str_repeat('a', 64),
        'update_available' => true,
        'checked_at' => now(),
    ]);

    Livewire::test(SystemInfoPage::class)
        ->callAction('updateNow')
        ->assertSet('updating', true);

    Queue::assertPushed(CoreUpdateJob::class);
    expect(CoreUpdater::progress()['state'])->toBe(CoreUpdateState::Queued->value);
    expect(CoreUpdateProgress::pending()?->expectedSha256)->toBe(str_repeat('a', 64));
});

it('does not treat a freshly queued update as stalled', function (): void {
    Queue::fake();

    $starter = app(CoreUpdateStarter::class);
    $starter->start(pendingUpdate());

    expect($starter->isStalled())->toBeFalse();
});

it('treats a queued update as stalled once the worker grace period passes', function (): void {
    Queue::fake();

    $starter = app(CoreUpdateStarter::class);
    $starter->start(pendingUpdate());

    $this->travel(CoreUpdateStarter::WORKER_GRACE_SECONDS + 5)->seconds();

    expect($starter->isStalled())->toBeTrue();
});

// A page polling every two seconds must not be able to start the apply twice.
it('consumes the pending release so the fallback can only run once', function (): void {
    Queue::fake();

    $starter = app(CoreUpdateStarter::class);
    $starter->start(pendingUpdate());
    $this->travel(CoreUpdateStarter::WORKER_GRACE_SECONDS + 5)->seconds();

    expect($starter->applyStalledInline())->toBe(CoreUpdateState::Failed);
    expect($starter->applyStalledInline())->toBeNull();
    expect($starter->isStalled())->toBeFalse();
});

it('applies a stalled update from the poll instead of sitting on Starting…', function (): void {
    Queue::fake();
    $this->actingAs(updaterSuperAdmin());

    app(CoreUpdateStarter::class)->start(pendingUpdate());
    $this->travel(CoreUpdateStarter::WORKER_GRACE_SECONDS + 5)->seconds();

    Livewire::test(SystemInfoPage::class)
        ->set('updating', true)
        ->call('pollCoreUpdate')
        ->assertSet('updating', false);

    // apply() ran (and failed at the checksum guard) rather than never running.
    expect(CoreUpdater::progress()['state'])->toBe(CoreUpdateState::Failed->value);
    expect(CoreUpdateProgress::pending())->toBeNull();
});

// The page is visible to settings.view, but the fallback overlays app/,
// bootstrap/ and src/Magna inside the request — same blast radius as
// "Update Now", which requires settings.manage. $updating is a plain public
// property, so a viewer could otherwise flip it and poll to start the apply.
it('refuses to run the stalled apply for a user who cannot manage settings', function (): void {
    Queue::fake();

    $role = Role::factory()->create(['handle' => 'viewer', 'name' => 'Viewer']);
    $role->grant('settings.view');
    $viewer = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $viewer->assignRole($role);

    app(CoreUpdateStarter::class)->start(pendingUpdate());
    $this->travel(CoreUpdateStarter::WORKER_GRACE_SECONDS + 5)->seconds();

    $this->actingAs($viewer);

    Livewire::test(SystemInfoPage::class)
        ->set('updating', true)
        ->call('pollCoreUpdate')
        ->assertSet('updating', true);

    // Still queued, release untouched — nothing was applied.
    expect(CoreUpdater::progress()['state'])->toBe(CoreUpdateState::Queued->value);
    expect(CoreUpdateProgress::pending())->not->toBeNull();
});

// The queued path must keep working — the fallback is a safety net, not a
// replacement. Uses a real database queue and a real worker pass, not a fake.
it('still applies through a real queue worker when one is running', function (): void {
    config()->set('queue.default', 'database');
    $this->actingAs(updaterSuperAdmin());

    app(CoreUpdateStarter::class)->start(pendingUpdate());
    expect(DB::table('jobs')->count())->toBe(1);

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    // apply() ran under the worker and failed closed at the checksum guard.
    expect(CoreUpdater::progress()['state'])->toBe(CoreUpdateState::Failed->value);
    expect(DB::table('jobs')->count())->toBe(0);
});

// After the fallback applies in-request, the job is still sitting in the queue.
// Whenever a worker finally starts, it must not re-download and re-overlay the
// same version, which would drop the site into maintenance mode again.
it('skips a queued job for a version the install already runs', function (): void {
    $job = new CoreUpdateJob(
        MagnaServiceProvider::VERSION,
        'https://github.com/magna-cms/magna/archive/v'.MagnaServiceProvider::VERSION.'.zip',
        str_repeat('b', 64),
    );

    $job->handle(app(CoreUpdater::class));

    // Nothing was attempted: no progress entry written at all.
    expect(CoreUpdater::progress()['state'])->toBeNull();
});

it('renders a percentage bar and the recent activity while updating', function (): void {
    $this->actingAs(updaterSuperAdmin());
    CoreUpdateProgress::set(CoreUpdateState::Running, 'Downloading release…', 20, '1.3.4');

    Livewire::test(SystemInfoPage::class)
        ->set('updating', true)
        ->assertSee('Updating Magna CMS v1.3.4')
        ->assertSee('20%')
        ->assertSee('Downloading release…')
        ->assertSee('width: 20%', escape: false)
        ->assertSee('syi-uptrack');
});

it('reports each step with a percentage and an ordered activity log', function (): void {
    $updater = app(CoreUpdater::class);

    $updater->apply('9.9.9', 'https://github.com/magna-cms/magna/archive/v9.9.9.zip', null);

    $progress = CoreUpdater::progress();
    expect($progress['version'])->toBe('9.9.9');
    expect($progress['percent'])->toBe(100);
    expect(array_column($progress['log'], 'message'))->toContain('Starting…');
});

it('keeps the log ordered, de-duplicated and bounded', function (): void {
    CoreUpdateProgress::set(CoreUpdateState::Running, 'Downloading release…', 20, '9.9.9');
    CoreUpdateProgress::set(CoreUpdateState::Running, 'Downloading release…', 20);
    CoreUpdateProgress::set(CoreUpdateState::Running, 'Extracting…', 65);

    for ($i = 0; $i < 30; $i++) {
        CoreUpdateProgress::set(CoreUpdateState::Running, "Step {$i}…", 70);
    }

    $progress = CoreUpdater::progress();
    expect(count($progress['log']))->toBeLessThanOrEqual(16);
    expect($progress['log'][count($progress['log']) - 1]['message'])->toBe('Step 29…');
    expect($progress['version'])->toBe('9.9.9');
});

it('clamps a percentage outside 0-100', function (): void {
    CoreUpdateProgress::set(CoreUpdateState::Running, 'Over…', 240);
    expect(CoreUpdater::progress()['percent'])->toBe(100);

    CoreUpdateProgress::set(CoreUpdateState::Running, 'Under…', -5);
    expect(CoreUpdater::progress()['percent'])->toBe(0);
});
