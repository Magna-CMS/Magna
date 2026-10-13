<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Magna\Admin\Pages\PluginsPage;
use Magna\Auth\Role;
use Magna\Marketplace\InstallPluginJob;
use Magna\Plugins\PluginRecord;
use Magna\Updater\UpdateCheck;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

// Update detection compared the on-disk manifest against the plugins row, so
// it only ever noticed files someone had already put there. Publishing a new
// version of a paid plugin to the marketplace left the Plugins page reading
// "Update Available (0)" while `magna:updater:check` reported that very
// update — the site knew, the page did not say so.

function pluginsPageAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'plugin-admin', 'name' => 'Plugin Admin']);
    $role->grant('settings.manage');
    $role->grant('plugins.manage');
    $role->grant('settings.view');

    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

function installedPlugin(string $name, string $version): PluginRecord
{
    return PluginRecord::query()->create([
        'name' => $name,
        'display_name' => 'Shop',
        'version' => $version,
        'enabled' => true,
        'base_path' => sys_get_temp_dir().'/'.str_replace('/', '-', $name),
        'manifest' => [
            'name' => $name,
            'displayName' => 'Shop',
            'description' => 'Shop.',
            'version' => $version,
            'author' => 'Acme',
            'license' => 'proprietary',
            'entry' => 'Acme\\Shop\\Plugin',
            'compat' => ['magna' => '^1.0', 'php' => '^8.3'],
            'permissions' => [],
            'provides' => [],
        ],
    ]);
}

it('reports a version published to the marketplace as an available update', function (): void {
    installedPlugin('acme/shop', '1.0.0');

    UpdateCheck::query()->create([
        'type' => 'plugin',
        'slug' => 'acme/shop',
        'current_version' => '1.0.0',
        'latest_version' => '1.1.0',
        'update_available' => true,
        'license_required' => false,
        'checked_at' => now(),
    ]);

    $page = Livewire::actingAs(pluginsPageAdmin())->test(PluginsPage::class);

    $installed = collect($page->get('installed'));
    expect($installed->pluck('name')->all())->toContain('acme/shop');

    $shop = $installed->firstWhere('name', 'acme/shop');

    expect($shop['update_version'] ?? null)->toBe('1.1.0');

    // And it is reachable through the "Update Available" tab, which is what
    // an admin actually looks at.
    $page->set('statusFilter', 'update')->assertSee('Shop');
});

it('does not claim an update when the marketplace reports none', function (): void {
    installedPlugin('acme/shop', '1.0.0');

    UpdateCheck::query()->create([
        'type' => 'plugin',
        'slug' => 'acme/shop',
        'current_version' => '1.0.0',
        'latest_version' => '1.0.0',
        'update_available' => false,
        'license_required' => false,
        'checked_at' => now(),
    ]);

    $page = Livewire::actingAs(pluginsPageAdmin())->test(PluginsPage::class);

    $shop = collect($page->get('installed'))->firstWhere('name', 'acme/shop');

    expect($shop['update_version'] ?? null)->toBeNull();

    $page->set('statusFilter', 'update')->assertDontSee('Shop');
});

// Seeing the update was only half the chain. The button beside the banner sent
// every unlicensed plugin to PluginManager::enable(), which re-reads what is
// already on disk — so for a plugin delivered by Composer it reported "Plugin
// updated." and changed nothing, and the banner came straight back.
it('fetches the new version with composer when the marketplace holds it', function (): void {
    installedPlugin('acme/shop', '1.0.0');

    UpdateCheck::query()->create([
        'type' => 'plugin',
        'slug' => 'acme/shop',
        'current_version' => '1.0.0',
        'latest_version' => '1.1.0',
        'update_available' => true,
        'checked_at' => now(),
    ]);

    Queue::fake();

    Livewire::actingAs(pluginsPageAdmin())
        ->test(PluginsPage::class)
        ->call('update', 'acme/shop')
        ->assertOk();

    Queue::assertPushed(InstallPluginJob::class, fn (InstallPluginJob $job): bool => $job->package === 'acme/shop' && $job->update);
});

// The other half of the branch: a plugins-dev plugin whose newer files someone
// already copied onto the disk has nothing to fetch, and enable() is the right
// and only thing to do. Queueing Composer for it would be wrong.
it('re-enables from disk when the marketplace is not offering anything', function (): void {
    installedPlugin('acme/local', '1.0.0');

    Queue::fake();

    Livewire::actingAs(pluginsPageAdmin())
        ->test(PluginsPage::class)
        ->call('update', 'acme/local');

    Queue::assertNotPushed(InstallPluginJob::class);
});
