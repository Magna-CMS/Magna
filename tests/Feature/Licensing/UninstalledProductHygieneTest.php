<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Livewire\Livewire;
use Magna\Admin\Pages\AccountCentrePage;
use Magna\Auth\Role;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\Updater\UpdateCheck;
use Magna\Users\User;

/**
 * An uninstalled product must stop advertising an update.
 *
 * The update-check row is written by the scheduled check-in and nothing
 * deleted it on uninstall, so the dashboard badge and the Account Centre
 * licences card went on offering "Update to X" for a plugin with no files on
 * the site — a button whose only possible outcome was "No licence for X is
 * active on this site."
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

function hygieneAdmin(): User
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

function phantomUpdateRow(string $slug): void
{
    UpdateCheck::query()->create([
        'type' => 'plugin',
        'slug' => $slug,
        'current_version' => '1.0.4',
        'latest_version' => '1.0.7',
        'update_available' => true,
        'checked_at' => now(),
    ]);
}

it('deletes the update-check row when a plugin is uninstalled', function (): void {
    PluginRecord::query()->create([
        'name' => 'acme/ghost',
        'display_name' => 'Ghost',
        'version' => '1.0.4',
        'base_path' => sys_get_temp_dir().'/acme-ghost-does-not-exist',
        'manifest' => ['name' => 'acme/ghost', 'displayName' => 'Ghost', 'version' => '1.0.4'],
        'enabled' => false,
    ]);
    phantomUpdateRow('acme/ghost');

    app(PluginManager::class)->uninstall('acme/ghost');

    expect(PluginRecord::query()->where('name', 'acme/ghost')->exists())->toBeFalse();
    expect(UpdateCheck::query()->where('slug', 'acme/ghost')->exists())->toBeFalse();
});

// Belt to the uninstall's braces: even while a stale row exists (written
// before this fix, or by a check-in racing an uninstall), the account page
// must not offer an update for a product that has no files here.
it('offers no product update for a plugin that is not installed', function (): void {
    $this->actingAs(hygieneAdmin());

    phantomUpdateRow('roya/dms');

    Livewire::test(AccountCentrePage::class)
        ->assertViewHas('productUpdates', [])
        ->assertViewHas('installedProducts', []);
});

it('still offers the update for a product that is installed', function (): void {
    $this->actingAs(hygieneAdmin());

    PluginRecord::query()->create([
        'name' => 'roya/dms',
        'display_name' => 'Roya DMS',
        'version' => '1.0.4',
        'base_path' => sys_get_temp_dir().'/roya-dms',
        'manifest' => ['name' => 'roya/dms', 'displayName' => 'Roya DMS', 'version' => '1.0.4'],
        'enabled' => true,
    ]);
    phantomUpdateRow('roya/dms');

    Livewire::test(AccountCentrePage::class)
        ->assertViewHas('productUpdates', ['roya/dms' => '1.0.7'])
        ->assertViewHas('installedProducts', ['roya/dms']);
});
