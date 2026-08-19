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
 * The environment card must report the debug state it actually found.
 *
 * The production branch of the card printed "Debug Mode Off" as fixed text —
 * it never read $debug_mode at all. So an operator who turned debug on, from
 * this very page's own toggle, was told by the same page that it was off,
 * while stack traces, environment values and query bindings went out to
 * whoever triggered an error. A status panel that cannot be believed is worse
 * than no panel: it talks an operator out of checking.
 *
 * Reported on a live install (magna1) after enabling debug mode.
 */
function systemInfoOperator(): User
{
    $role = Role::factory()->create(['handle' => 'sysop', 'name' => 'Sysop']);
    $role->grant('settings.view', 'settings.manage');
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('reports debug as active in production when it is on', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => true]);

    $this->actingAs(systemInfoOperator());

    Livewire::test(SystemInfoPage::class)
        ->assertSee('Debug Mode Active')
        ->assertDontSee('Debug Mode Off');
});

it('reports debug as off in production when it is off', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => false]);

    $this->actingAs(systemInfoOperator());

    Livewire::test(SystemInfoPage::class)
        ->assertSee('Debug Mode Off')
        ->assertDontSee('Debug Mode Active');
});

it('reports debug as active outside production when it is on', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    config(['app.debug' => true]);

    $this->actingAs(systemInfoOperator());

    Livewire::test(SystemInfoPage::class)
        ->assertSee('Debug Mode Active')
        ->assertDontSee('Debug Mode Off');
});

/**
 * Tailwind compiles the classes it can find as literal strings in the source.
 * A class assembled at runtime — "bg-{$tone}-100" — is never emitted, and the
 * card renders with no colour, which is how the red debug-in-production
 * warning would quietly turn into plain text.
 */
it('renders colour classes Tailwind can actually compile', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => true]);

    $this->actingAs(systemInfoOperator());

    Livewire::test(SystemInfoPage::class)
        ->assertSee('bg-red-100', escape: false)
        ->assertSee('text-red-500', escape: false);
});

/**
 * Icon spans carry a Material Symbols ligature, which renders as its own name
 * in plain text when the font has no glyph for it — which is what
 * "shield_with_house" was doing on the environment card.
 */
it('uses no icon ligature that renders as raw text', function (): void {
    $view = file_get_contents(
        base_path('src/Magna/Admin/Resources/views/admin/system-info.blade.php')
    );

    expect($view)->not->toContain('shield_with_house');
});
