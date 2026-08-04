<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Magna\Admin\Pages\PluginsPage;
use Magna\Auth\Role;
use Magna\Marketplace\InstallPluginJob;
use Magna\Marketplace\InstallProgress;
use Magna\Marketplace\InstallState;
use Magna\Marketplace\Marketplace;
use Magna\Marketplace\PluginInstaller;
use Magna\Marketplace\PluginInstallStarter;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();
});

function installFallbackAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

// The reported bug: with QUEUE_CONNECTION=database and no worker running, the
// job sat in the jobs table and the Plugins page showed "Queued…" forever.
// install() had never been entered, so nothing had written progress at all.
it('records a queued state and the package before dispatching', function (): void {
    Queue::fake();

    app(PluginInstallStarter::class)->start('acme/forum');

    Queue::assertPushed(InstallPluginJob::class);

    $progress = PluginInstaller::progress('acme/forum');
    expect($progress['state'])->toBe(InstallState::Queued->value);
    expect($progress['message'])->toContain('background worker');
    expect(InstallProgress::isPending('acme/forum'))->toBeTrue();
});

it('does not treat a freshly queued install as stalled', function (): void {
    Queue::fake();
    $starter = app(PluginInstallStarter::class);

    $starter->start('acme/forum');

    expect($starter->isStalled('acme/forum'))->toBeFalse();
});

it('treats a queued install as stalled once the worker grace period passes', function (): void {
    Queue::fake();
    $starter = app(PluginInstallStarter::class);
    $starter->start('acme/forum');

    $this->travel(PluginInstallStarter::WORKER_GRACE_SECONDS + 5)->seconds();

    expect($starter->isStalled('acme/forum'))->toBeTrue();
});

// Only packages this install queued itself may be installed by the fallback:
// `installQueue` is a plain public Livewire property, so a browser can put any
// name in it.
it('refuses to install a package the server never queued', function (): void {
    $starter = app(PluginInstallStarter::class);

    expect($starter->isStalled('evil/backdoor'))->toBeFalse();
    expect($starter->installStalledInline('evil/backdoor'))->toBeNull();
});

// The package name arrives from the browser (pendingPluginName / installQueue
// are plain public Livewire properties) and lands in a cache key and a queued
// job payload, so the starter refuses anything that isn't vendor/package.
it('rejects a package name that is not a valid vendor/package', function (): void {
    Queue::fake();
    $starter = app(PluginInstallStarter::class);

    foreach (['', 'nope', '../../etc/passwd', 'Acme/Forum', 'acme/forum;rm -rf /', 'acme//forum'] as $bad) {
        expect($starter->start($bad))->toBeFalse();
        expect($starter->isStalled($bad))->toBeFalse();
        expect($starter->installStalledInline($bad))->toBeNull();
    }

    Queue::assertNothingPushed();
});

it('consumes the queued marker so the fallback can only run once', function (): void {
    Http::fake([Marketplace::API_BASE.'/*' => Http::response([])]);
    Queue::fake();
    $starter = app(PluginInstallStarter::class);
    $starter->start('acme/forum');
    $this->travel(PluginInstallStarter::WORKER_GRACE_SECONDS + 5)->seconds();

    // Not an approved listing (the faked catalog is empty), so install() fails
    // at its trust boundary — which is enough to prove it ran.
    expect($starter->installStalledInline('acme/forum'))->toBe(InstallState::Failed);
    expect($starter->installStalledInline('acme/forum'))->toBeNull();
    expect($starter->isStalled('acme/forum'))->toBeFalse();
});

it('runs a stalled install from the poll instead of sitting on Queued', function (): void {
    Http::fake([Marketplace::API_BASE.'/*' => Http::response([])]);
    Queue::fake();
    $this->actingAs(installFallbackAdmin());

    app(PluginInstallStarter::class)->start('acme/forum');
    $this->travel(PluginInstallStarter::WORKER_GRACE_SECONDS + 5)->seconds();

    Livewire::test(PluginsPage::class)
        ->set('installQueue', ['acme/forum'])
        ->call('pollInstalls')
        ->assertSet('installQueue', []);

    // install() ran (and refused an unapproved package) rather than never running.
    expect(PluginInstaller::progress('acme/forum')['state'])->toBe(InstallState::Failed->value);
    expect(InstallProgress::isPending('acme/forum'))->toBeFalse();
});

// Losing the installer's serial lock is not a failure: the package has to go
// back in the queue, or it would be dropped and never installed.
it('re-queues a package when another install holds the lock', function (): void {
    Http::fake([Marketplace::API_BASE.'/*' => Http::response([])]);
    Queue::fake();
    $starter = app(PluginInstallStarter::class);
    $starter->start('acme/forum');
    $this->travel(PluginInstallStarter::WORKER_GRACE_SECONDS + 5)->seconds();

    $lock = Cache::lock('magna.marketplace.install.lock', 900);
    expect($lock->get())->toBeTrue();

    expect($starter->installStalledInline('acme/forum'))->toBe(InstallState::Queued);
    expect(InstallProgress::isPending('acme/forum'))->toBeTrue();
    expect(PluginInstaller::progress('acme/forum')['message'])->toContain('another installation');

    $lock->release();
});
