<?php

declare(strict_types=1);

/**
 * Where the admin panel answers.
 *
 * Magna has always served it from "/", which leaves a frontend plugin's own
 * routes nothing to bind the bare domain to: a site's home page was only ever
 * reachable at its own slug. An operator can now hand the root to the site and
 * move the panel to /admin.
 *
 * The panel is also the only way back to that switch, so the tests below are
 * as much about the failure paths as the feature: PanelPath is read during the
 * panel provider's register(), earlier than any other settings read in the
 * application, and every way that read can go wrong has to resolve to the root
 * rather than to an exception or to a path nobody can reach.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Magna\Admin\PanelPath;
use Magna\Admin\PanelPathSwitcher;
use Magna\Admin\PanelSettings;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // PanelPath refuses to look at the database at all before installation,
    // because asking an uninstalled host for a table is itself a connection
    // attempt. The suite runs against a migrated database, so say so.
    config(['magna.installed_override' => true]);
});

it('serves the panel from the domain root by default', function (): void {
    expect(PanelPath::current())->toBe(PanelPath::ROOT)
        ->and(PanelPath::enabled())->toBeFalse();
});

it('moves the panel to /admin once the setting is on', function (): void {
    $settings = PanelSettings::get();
    $settings->admin_prefix = true;
    $settings->save();

    expect(PanelPath::current())->toBe('admin')
        ->and(PanelPath::enabled())->toBeTrue();
});

it('falls back to the root when the settings table is not there yet', function (): void {
    $settings = PanelSettings::get();
    $settings->admin_prefix = true;
    $settings->save();

    // A checkout mid-migration: the switch says /admin and the store that
    // holds it has gone. The panel must still answer somewhere.
    Schema::drop('settings');

    expect(PanelPath::current())->toBe(PanelPath::ROOT);
});

it('falls back to the root before the site is installed', function (): void {
    $settings = PanelSettings::get();
    $settings->admin_prefix = true;
    $settings->save();

    config(['magna.installed_override' => false]);

    expect(PanelPath::current())->toBe(PanelPath::ROOT);
});

it('hands the panel provider the path it resolves', function (): void {
    // The provider builds its panel during register(), so the running app's
    // panel is the proof that the two are wired together at all.
    expect(filament()->getPanel('magna')->getPath())->toBe(PanelPath::current());
});

it('clears the route cache when the path changes, or the compiled routes win', function (): void {
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->once()->with('route:clear');

    (new PanelPathSwitcher($kernel))->set(true);

    expect(PanelSettings::get()->admin_prefix)->toBeTrue();
});

it('does no work and clears nothing when the path is already what was asked for', function (): void {
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->never();

    (new PanelPathSwitcher($kernel))->set(false);

    expect(PanelSettings::get()->admin_prefix)->toBeFalse();
});

it('reports the path as a URL a browser can be sent to', function (): void {
    $switcher = app(PanelPathSwitcher::class);

    expect($switcher->url())->toBe('/');

    $settings = PanelSettings::get();
    $settings->admin_prefix = true;
    $settings->save();

    expect($switcher->url())->toBe('/admin');
});

it('shows and moves the path from the console', function (): void {
    $this->artisan('magna:panel:path')
        ->expectsOutputToContain('/')
        ->assertSuccessful();

    $this->artisan('magna:panel:path --admin')
        ->expectsOutputToContain('/admin')
        ->assertSuccessful();

    expect(PanelPath::enabled())->toBeTrue();

    // The way back in, which is the entire reason the command exists.
    $this->artisan('magna:panel:path --root')->assertSuccessful();

    expect(PanelPath::enabled())->toBeFalse();
});

it('refuses a console call that asks for both paths at once', function (): void {
    $this->artisan('magna:panel:path --root --admin')->assertFailed();
});
