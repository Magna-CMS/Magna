<?php

declare(strict_types=1);

/**
 * The Plugins page against ghost rows.
 *
 * A failed install rolls its record back, but the Livewire component still
 * renders the list from before — offering Enable and Uninstall for a plugin
 * that no longer exists. Acting on such a row must not surface the CLI-flavoured
 * "Run `composer require` first" message or leave the ghost on screen.
 */

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Magna\Admin\Pages\PluginsPage;
use Magna\Auth\Role;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();
    Http::fake();
});

function staleRowAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('treats uninstalling an already-removed plugin as done, not as a failure', function (): void {
    $this->actingAs(staleRowAdmin());

    Livewire::test(PluginsPage::class)
        ->set('pendingPluginName', 'ghost/rolled-back')
        ->callAction('uninstall')
        ->assertNotified('Plugin already removed — refreshing the list.')
        ->assertSet('pendingPluginName', null);
});

it('treats purging an already-removed plugin as done, not as a failure', function (): void {
    $this->actingAs(staleRowAdmin());

    Livewire::test(PluginsPage::class)
        ->set('pendingPluginName', 'ghost/rolled-back')
        ->callAction('purge')
        ->assertNotified('Plugin already removed — refreshing the list.')
        ->assertSet('pendingPluginName', null);
});

it('re-queries the plugin list when enabling a ghost row fails', function (): void {
    $this->actingAs(staleRowAdmin());

    // Enabling something with no record and no files fails — and the failure
    // path must refresh the list so the ghost row disappears without a hard
    // refresh. Seed the component with the stale row a rolled-back install
    // leaves behind, then watch the re-query evict it.
    $staleRow = [
        'name' => 'ghost/rolled-back',
        'display_name' => 'Ghost',
        'version' => '0.1.0',
        'enabled' => false,
        'description' => '',
        'author' => '',
        'source' => 'plugins-dev/',
        'update_version' => null,
        'settings_url' => null,
        'icon' => null,
        'official' => false,
        'verified' => false,
        'requires_license' => false,
        'license_blocked_version' => null,
    ];

    Livewire::test(PluginsPage::class)
        ->set('installed', [$staleRow])
        ->call('enable', 'ghost/rolled-back')
        ->assertNotified('Failed to enable plugin')
        ->assertSet('installed', fn (array $installed): bool => ! collect($installed)->pluck('name')->contains('ghost/rolled-back'));
});
