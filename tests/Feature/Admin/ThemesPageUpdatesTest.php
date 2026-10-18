<?php

declare(strict_types=1);

/**
 * The Themes screen surfaces marketplace updates. Before this, the Installed
 * card offered only Remove/Deactivate and the Browse tab filtered out anything
 * already on disk — so a theme published to the marketplace could never be
 * updated from the panel at all, even with a licence entitled to it.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\Admin\Pages\ThemesPage;
use Magna\Auth\Role;
use Magna\Licensing\LicenseInstaller;
use Magna\Updater\UpdateCheck;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function themesUpdatesAdmin(): User
{
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'settings.manage');
    $user->assignRole($role);

    return $user;
}

function themeCheckRow(array $attributes = []): void
{
    UpdateCheck::query()->create($attributes + [
        'type' => 'theme',
        'slug' => 'testv/fixture',
        'current_version' => '1.0.0',
        'latest_version' => '1.7.0',
        'update_available' => true,
        'checked_at' => now(),
    ]);
}

it('offers an update on the installed-theme card when the check-in recorded a newer version', function (): void {
    $this->actingAs(themesUpdatesAdmin());
    themeCheckRow();

    Livewire::test(ThemesPage::class)
        ->assertSee('Version 1.7.0 is available.')
        ->assertSee('Update now');
});

it('offers a renew prompt, never an update button, when the licence no longer covers the newer version', function (): void {
    $this->actingAs(themesUpdatesAdmin());
    themeCheckRow(['update_available' => false, 'license_required' => true]);

    Livewire::test(ThemesPage::class)
        ->assertSee('your licence no longer covers updates')
        ->assertDontSee('Update now');
});

it('offers nothing when the check-in recorded no newer version', function (): void {
    $this->actingAs(themesUpdatesAdmin());
    themeCheckRow(['update_available' => false, 'latest_version' => '1.0.0']);

    Livewire::test(ThemesPage::class)
        ->assertDontSee('Update now')
        ->assertDontSee('no longer covers updates');
});

// The row records what was true at CHECK time. Theme files move without it —
// a zip upload, a manual copy into themes/, a restored backup — so the offer
// is re-tested against the manifest on disk before it is drawn. Without this,
// the stale row offers to "update" a site onto a version it has already left.
it('offers nothing when the recorded version is the one already on disk', function (): void {
    $this->actingAs(themesUpdatesAdmin());
    themeCheckRow(['latest_version' => '1.0.0']); // fixture theme.json is 1.0.0

    Livewire::test(ThemesPage::class)->assertDontSee('Update now');
});

it('offers nothing when the theme on disk has moved past the recorded version', function (): void {
    $this->actingAs(themesUpdatesAdmin());
    themeCheckRow(['current_version' => '0.9.0', 'latest_version' => '0.9.5']);

    Livewire::test(ThemesPage::class)->assertDontSee('Update now');
});

// 'v1.7.0' and '1.7.0' are the same release; the hub may send either.
it('reads a v-prefixed recorded version as the same release', function (): void {
    $this->actingAs(themesUpdatesAdmin());
    themeCheckRow(['latest_version' => 'v1.0.0']);

    Livewire::test(ThemesPage::class)->assertDontSee('Update now');
});

it('still offers a v-prefixed version that is genuinely newer', function (): void {
    $this->actingAs(themesUpdatesAdmin());
    themeCheckRow(['latest_version' => 'v1.7.0']);

    Livewire::test(ThemesPage::class)->assertSee('Version 1.7.0 is available.');
});

// A renew prompt for a version the site already has is the same lie in a
// different colour, so the comparison gates both halves of the split.
it('drops the renew prompt too when the disk is already at the blocked version', function (): void {
    $this->actingAs(themesUpdatesAdmin());
    themeCheckRow(['update_available' => false, 'license_required' => true, 'latest_version' => '1.0.0']);

    Livewire::test(ThemesPage::class)->assertDontSee('no longer covers updates');
});

// Addons update through the same licence path as their host theme.
it('offers an update on an addon card too', function (): void {
    $this->actingAs(themesUpdatesAdmin());
    themeCheckRow(['slug' => 'addonvendor/pages-kit', 'current_version' => '0.1.0', 'latest_version' => '0.2.0']);

    Livewire::test(ThemesPage::class)
        ->assertSee('Version 0.2.0 is available.');
});

it('updates a theme through the licence installer and reports its message', function (): void {
    $this->actingAs(themesUpdatesAdmin());
    themeCheckRow();

    $installer = Mockery::mock(LicenseInstaller::class);
    $installer->shouldReceive('update')->once()->with('testv/fixture')->andReturn('Fixture Theme updated to 1.7.0.');
    app()->instance(LicenseInstaller::class, $installer);

    Livewire::test(ThemesPage::class)
        ->call('update', 'testv/fixture')
        ->assertNotified('Fixture Theme updated to 1.7.0.');
});

// The refusal reasons come from the licence server (lapsed entitlement,
// pending review, dead token) — the page's job is to surface them, not eat them.
it('surfaces a refused theme update as a failure notification', function (): void {
    $this->actingAs(themesUpdatesAdmin());
    themeCheckRow();

    $installer = Mockery::mock(LicenseInstaller::class);
    $installer->shouldReceive('update')->once()->andThrow(new RuntimeException('No licence for testv/fixture is active on this site.'));
    app()->instance(LicenseInstaller::class, $installer);

    Livewire::test(ThemesPage::class)
        ->call('update', 'testv/fixture')
        ->assertNotified('Update failed');
});
