<?php

declare(strict_types=1);

/**
 * The sidebar's group order is registered, not emergent.
 *
 * Filament positions registered groups by their index and sends unregistered
 * ones to the end; with nothing registered, a group sat wherever its
 * lowest-sorted item fell in the global item sort. A plugin page with no
 * declared sort (-1) jumped its whole group above Content, and a plugin
 * registering its group label through RegistersAdminNavigation leapfrogged
 * every core group. The order is now: Dashboard (ungrouped, always first),
 * plugin groups alphabetically, then the core groups.
 */

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Admin\AdminServiceProvider;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Registered group labels on the magna panel, in order. */
function registeredGroupLabels(): array
{
    return array_map(
        fn (NavigationGroup $group): string => (string) $group->getLabel(),
        Filament::getPanel('magna')->getNavigationGroups(),
    );
}

it('holds the core groups in their declared order at the end', function (): void {
    // The booted hook has run (no enabled plugins in this app), so the
    // registered list is exactly the core order.
    expect(registeredGroupLabels())->toBe(AdminServiceProvider::CORE_NAVIGATION_GROUP_ORDER);
});

it('orders plugin groups alphabetically, before every core group', function (): void {
    $provider = new AdminServiceProvider(app());

    $ordered = (fn (): array => $this->orderedNavigationGroups([
        NavigationGroup::make('Zeta Plugin'),
        NavigationGroup::make('Alpha Plugin'),
        // A plugin filing its page under a core group registers nothing new.
        NavigationGroup::make('System'),
    ]))->call($provider);

    expect(array_map(fn (NavigationGroup $g): string => (string) $g->getLabel(), $ordered))->toBe([
        'Alpha Plugin',
        'Zeta Plugin',
        ...AdminServiceProvider::CORE_NAVIGATION_GROUP_ORDER,
    ]);
});
