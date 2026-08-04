<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\Admin\Pages\SystemInfoPage;
use Magna\Auth\Role;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

/**
 * The page is reachable with `settings.view` (a read-only permission any
 * support/auditor role can hold), but two of its Livewire methods are not
 * read-only: toggleDebugMode() writes APP_DEBUG to .env — which turns stack
 * traces, SQL and environment dumps on for every visitor of the site — and
 * clearCache() flushes the application cache. Livewire methods are callable by
 * anyone who can render the component whether or not the button that calls them
 * was rendered for them, so page access alone must not authorize either.
 */
function systemInfoViewer(): User
{
    $role = Role::factory()->create(['handle' => 'auditor', 'name' => 'Auditor']);
    $role->grant('settings.view');
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

function systemInfoAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'ops', 'name' => 'Ops']);
    $role->grant('settings.view', 'settings.manage');
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('refuses toggleDebugMode for a settings.view-only user', function (): void {
    $this->actingAs(systemInfoViewer());

    $before = (string) file_get_contents(base_path('.env'));

    Livewire::test(SystemInfoPage::class)
        ->call('toggleDebugMode')
        ->assertForbidden();

    expect((string) file_get_contents(base_path('.env')))->toBe($before);
});

it('refuses clearCache for a settings.view-only user', function (): void {
    $this->actingAs(systemInfoViewer());

    Livewire::test(SystemInfoPage::class)
        ->call('clearCache')
        ->assertForbidden();
});

it('allows clearCache for a user who can manage settings', function (): void {
    $this->actingAs(systemInfoAdmin());

    Livewire::test(SystemInfoPage::class)
        ->call('clearCache')
        ->assertOk();
});

it('still lets a viewer read the page and run read-only diagnostics', function (): void {
    $this->actingAs(systemInfoViewer());

    Livewire::test(SystemInfoPage::class)
        ->call('runDiagnostics')
        ->assertOk();
});
