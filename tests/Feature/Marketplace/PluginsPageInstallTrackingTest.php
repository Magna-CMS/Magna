<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Magna\Admin\Pages\PluginsPage;
use Magna\Auth\Role;
use Magna\Marketplace\ComposerRunner;
use Magna\Marketplace\InstallProgress;
use Magna\Marketplace\InstallState;
use Magna\Marketplace\Marketplace;
use Magna\Marketplace\MarketplaceClient;
use Magna\Marketplace\PluginInstaller;
use Magna\Users\User;
use Tests\Support\FakeComposerRunner;
use Tests\TestCase;

/*
 * An update clicked on the Installed tab looked like nothing at all: the
 * only poll lived in the Add New panel, the watch list was a Livewire
 * property a reload forgot, the toasts said "installed" for an update, and
 * an exception inside the installer froze progress at "Downloading…".
 */

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();
    Http::fake([Marketplace::API_BASE.'/*' => Http::response([])]);
});

function trackingAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('keeps watching a queued update after a reload', function (): void {
    $this->actingAs(trackingAdmin());
    InstallProgress::markQueued('acme/forum', update: true);

    Livewire::test(PluginsPage::class)->assertSet('installQueue', ['acme/forum']);
});

it('polls on the Installed tab, not only on Add New', function (): void {
    $this->actingAs(trackingAdmin());
    InstallProgress::markQueued('acme/forum', update: true);

    Livewire::test(PluginsPage::class)
        ->assertSet('activeTab', 'installed')
        ->assertSeeHtml('wire:poll.2s="pollInstalls"');
});

it('words a finished update as an update', function (): void {
    $this->actingAs(trackingAdmin());
    InstallProgress::markQueued('acme/forum', update: true);
    InstallProgress::set('acme/forum', InstallState::Completed, 'Updated.');

    Livewire::test(PluginsPage::class)
        ->call('pollInstalls')
        ->assertNotified('acme/forum updated.')
        ->assertSet('installQueue', []);

    expect(InstallProgress::active())->toBe([]);
});

it('words a failed update as an update and shows why', function (): void {
    $this->actingAs(trackingAdmin());
    InstallProgress::markQueued('acme/forum', update: true);
    InstallProgress::set('acme/forum', InstallState::Failed, 'Composer said no.');

    Livewire::test(PluginsPage::class)
        ->call('pollInstalls')
        ->assertNotified("Couldn't update acme/forum");
});

it('ends a run that throws as Failed, with the lock released', function (): void {
    app()->instance(ComposerRunner::class, new FakeComposerRunner);
    $market = Mockery::mock(MarketplaceClient::class);
    $market->shouldReceive('plugin')->andThrow(new RuntimeException('catalog timed out'));
    app()->instance(MarketplaceClient::class, $market);

    $state = app(PluginInstaller::class)->update('acme/forum');

    expect($state)->toBe(InstallState::Failed)
        ->and(PluginInstaller::progress('acme/forum')['message'])->toContain('catalog timed out')
        // A stuck lock would park every later install at "Waiting…".
        ->and(Cache::lock('magna.marketplace.install.lock', 1)->get())->toBeTrue();
});
