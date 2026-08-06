<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Magna\Auth\Role;
use Magna\Licensing\LicenseEntry;
use Magna\Licensing\LicenseGate;
use Magna\Licensing\LicenseState;
use Magna\Licensing\LicenseStore;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\Users\User;

// Releasing a seat frees it on the marketplace AND forgets the local licence
// entry. LicenseGate read a missing entry as "unlicensed", which boots — the
// rule that lets free plugins run. So a paid plugin kept serving after its
// seat was released, and the same key could be activated on the next domain:
// release, activate, repeat, and one seat quietly powered any number of
// sites. The marketplace's 3-moves-per-30-days cap does not help, because
// every site released along the way keeps working forever.
//
// A plugin installed through the licensed download path is now remembered as
// such, so "no entry" means the licence is gone rather than never needed.

function paidPluginRecord(string $name = 'acme/paid'): PluginRecord
{
    return PluginRecord::query()->create([
        'name' => $name,
        'display_name' => 'Paid Plugin',
        'version' => '1.0.0',
        'enabled' => true,
        'requires_license' => true,
        'base_path' => sys_get_temp_dir().'/'.str_replace('/', '-', $name),
        'manifest' => [
            'name' => $name,
            'displayName' => 'Paid Plugin',
            'description' => 'Paid.',
            'version' => '1.0.0',
            'author' => 'Acme',
            'license' => 'proprietary',
            'entry' => 'Acme\\Paid\\Plugin',
            'compat' => ['magna' => '^1.0', 'php' => '^8.3'],
            'permissions' => [],
            'provides' => [],
        ],
    ]);
}

function cachePaidLicense(string $slug): void
{
    app(LicenseStore::class)->put(new LicenseEntry(
        productSlug: $slug,
        token: str_repeat('t', 64),
        status: 'active',
        licenseType: 'corporate',
        expiresAt: null,
        updateEntitled: true,
        serverTime: Carbon::now(),
        lastVerifiedAt: Carbon::now(),
        autoDisabled: false,
    ));
}

it('locks a licensed-install plugin once its licence entry is gone', function (): void {
    $gate = app(LicenseGate::class);

    // The released seat: nothing left in the local store.
    expect(app(LicenseStore::class)->get('acme/paid'))->toBeNull();

    expect($gate->stateOf('acme/paid', requiresLicense: true))->toBe(LicenseState::Locked)
        ->and($gate->isLocked('acme/paid', requiresLicense: true))->toBeTrue();
});

it('still boots a free plugin that never had a licence', function (): void {
    $gate = app(LicenseGate::class);

    // The rule this mechanism must preserve: most plugins are free, have no
    // entry, and have to load.
    expect($gate->stateOf('acme/free'))->toBe(LicenseState::Unlicensed)
        ->and($gate->isLocked('acme/free'))->toBeFalse();
});

it('keeps a released plugin out of the booted set', function (): void {
    paidPluginRecord();

    // bootEnabledPlugins() rejects locked records before anything registers,
    // so the plugin is absent even though the plugins table says enabled.
    expect(app(PluginManager::class)->getEnabled())->not->toHaveKey('acme/paid');
});

it('disables the plugin when its seat is released from the account page', function (): void {
    $record = paidPluginRecord();
    cachePaidLicense('acme/paid');

    $role = Role::factory()->create(['handle' => 'licensing', 'name' => 'Licensing']);
    $role->grant('licensing.manage');
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    $this->actingAs($user)
        ->post(route('licensing.deactivate'), ['product_slug' => 'acme/paid'])
        ->assertRedirect();

    // Not merely unlicensed — switched off, so the Plugins page stops showing
    // "Active" for something that can no longer load.
    expect($record->fresh()->enabled)->toBeFalse()
        ->and(app(LicenseStore::class)->get('acme/paid'))->toBeNull();
});
