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
 * support/auditor role can hold), but clearCache() flushes the application
 * cache — not read-only. Livewire methods are callable by anyone who can
 * render the component whether or not the button that calls them was
 * rendered for them, so page access alone must not authorize it.
 *
 * The page also used to expose toggleDebugMode(), which wrote APP_DEBUG to
 * .env from the browser — stack traces, SQL and environment dumps for every
 * visitor, one compromised admin session away. Removed outright: that flag
 * is a server-operator decision made at the shell. The test below pins the
 * removal.
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

it('offers no panel method that writes APP_DEBUG, for anyone', function (): void {
    // Regression pin on the removal: no Livewire method on this page may
    // toggle debug mode again — not even behind settings.manage.
    expect(method_exists(SystemInfoPage::class, 'toggleDebugMode'))->toBeFalse();

    $source = (string) file_get_contents((new ReflectionClass(SystemInfoPage::class))->getFileName());
    expect($source)->not->toContain("preg_replace('/^APP_DEBUG");
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
