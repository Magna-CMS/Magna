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
use Magna\Marketplace\InstallState;
use Magna\Marketplace\Marketplace;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();
});

function pluginsAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('lists marketplace plugins in the Add New tab', function (): void {
    Http::fake([Marketplace::API_BASE.'/*' => Http::response([
        ['package' => 'acme/forum', 'name' => 'Acme Forum', 'shortDescription' => 'Community forums', 'version' => '1.2.0', 'compat' => '^1.0', 'permissions' => ['forum.thread.manage']],
    ])]);
    $this->actingAs(pluginsAdmin());

    Livewire::test(PluginsPage::class)
        ->call('setTab', 'addnew')
        ->assertSee('Acme Forum');
});

it('queues a background install job when a plugin install is confirmed', function (): void {
    Http::fake([Marketplace::API_BASE.'/*' => Http::response([
        ['package' => 'acme/forum', 'name' => 'Acme Forum', 'version' => '1.2.0', 'compat' => '^1.0'],
    ])]);
    Queue::fake();
    $this->actingAs(pluginsAdmin());

    Livewire::test(PluginsPage::class)
        ->set('pendingPluginName', 'acme/forum')
        ->callAction('install')
        ->assertSet('installQueue', ['acme/forum']);

    Queue::assertPushed(InstallPluginJob::class, fn (InstallPluginJob $job): bool => $job->package === 'acme/forum');
});

it('clears finished installs from the queue when polled', function (): void {
    Http::fake([Marketplace::API_BASE.'/*' => Http::response([])]);
    $this->actingAs(pluginsAdmin());

    Cache::put('magna.marketplace.install.acme/forum', ['state' => InstallState::Completed->value, 'message' => 'Installed.'], 900);

    Livewire::test(PluginsPage::class)
        ->set('installQueue', ['acme/forum'])
        ->call('pollInstalls')
        ->assertSet('installQueue', []);
});

// A manifest records "1.1.0" while the marketplace records the Composer tag
// the release was published under, "v1.1.0". The card renders "v{version}"
// either way, so a marketplace listing came out reading "vv1.1.0".
it('shows one v on a marketplace version published under a v-prefixed tag', function (): void {
    Http::fake([Marketplace::API_BASE.'/*' => Http::response([
        ['package' => 'acme/forum', 'name' => 'Acme Forum', 'version' => 'v1.2.0', 'compat' => '^1.0'],
    ])]);
    $this->actingAs(pluginsAdmin());

    Livewire::test(PluginsPage::class)
        ->call('setTab', 'addnew')
        ->assertSee('v1.2.0')
        ->assertDontSee('vv1.2.0');
});
