<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\Admin\AdminAssetPublisher;
use Magna\Admin\Pages\SystemInfoPage;
use Magna\Auth\Role;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The console's maintenance actions run real artisan commands — migrations,
 * cache rebuild, asset publish, queue restart — so every one of them is a
 * site-wide side effect behind settings.manage, and every one prints what it
 * actually ran. Livewire methods are callable by anyone who can render the
 * component, whether or not their button rendered, so the gate lives in the
 * method (the clearCache precedent).
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    // The real filament:assets rewrites tracked files under public/ in
    // whatever checkout runs the suite.
    $this->app->instance(AdminAssetPublisher::class, new class extends AdminAssetPublisher
    {
        public function publish(): void {}

        public function requestPublish(): void {}
    });
});

function maintenanceViewer(): User
{
    $role = Role::factory()->create(['handle' => 'auditor', 'name' => 'Auditor']);
    $role->grant('settings.view');
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

function maintenanceAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'ops', 'name' => 'Ops']);
    $role->grant('settings.view', 'settings.manage');
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('refuses every maintenance action for a settings.view-only user', function (string $method): void {
    $this->actingAs(maintenanceViewer());

    Livewire::test(SystemInfoPage::class)
        ->call($method)
        ->assertForbidden();
})->with(['runMigrations', 'rebuildCaches', 'publishAssets', 'restartQueue']);

it('runs migrations for the host and every enabled plugin, printing real output', function (): void {
    $this->actingAs(maintenanceAdmin());

    $lines = Livewire::test(SystemInfoPage::class)
        ->call('runMigrations')
        ->assertOk()
        ->get('terminalLines');

    $texts = array_column($lines, 'text');
    $types = array_column($lines, 'type');

    expect($texts)->toContain('php artisan migrate --force')
        ->and($types)->toContain('success')
        ->and($types)->not->toContain('error');
});

it('rebuilds caches without warming caches that were cold', function (): void {
    $this->actingAs(maintenanceAdmin());

    $lines = Livewire::test(SystemInfoPage::class)
        ->call('rebuildCaches')
        ->assertOk()
        ->get('terminalLines');

    $texts = implode("\n", array_column($lines, 'text'));

    // The suite never runs config:cache/route:cache, so nothing may re-warm —
    // a site that never caches must not be made to start.
    expect($texts)->toContain('[SUCCESS] Caches rebuilt.')
        ->and($texts)->not->toContain('re-warmed')
        ->and(app()->configurationIsCached())->toBeFalse();
});

it('publishes assets and signals a queue restart, with real output lines', function (string $method, string $success): void {
    $this->actingAs(maintenanceAdmin());

    $lines = Livewire::test(SystemInfoPage::class)
        ->call($method)
        ->assertOk()
        ->get('terminalLines');

    expect(implode("\n", array_column($lines, 'text')))->toContain($success);
})->with([
    ['publishAssets', '[SUCCESS] Admin assets published.'],
    ['restartQueue', '[SUCCESS] Workers will exit'],
]);
