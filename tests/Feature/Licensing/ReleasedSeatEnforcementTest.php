<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Magna\Licensing\LicenseEntry;
use Magna\Licensing\LicenseGate;
use Magna\Licensing\LicenseState;
use Magna\Licensing\LicenseStore;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;

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

it('keeps a released plugin out of the booted set, and says so in the log', function (): void {
    paidPluginRecord();

    $entries = [];

    Log::listen(function ($entry) use (&$entries): void {
        $entries[] = $entry;
    });

    // Booted explicitly: the app's own boot ran before this record existed,
    // so without this the filter under test never sees it and the assertions
    // below would pass against an absence nothing had to earn.
    $manager = app(PluginManager::class);
    $manager->bootEnabledPlugins();

    // bootEnabledPlugins() rejects locked records before anything registers,
    // so the plugin is absent even though the plugins table says enabled.
    expect($manager->getEnabled())->not->toHaveKey('acme/paid');

    /*
     * The skip must never be silent again. Under Octane this filter runs once
     * per worker, and a worker that boots while the licence cache reads empty
     * serves without the plugin for its whole life - with cached routes still
     * resolving, so every policy answers a bare 403. A licensed site spent a
     * day chasing those before anything named the cause.
     */
    $skip = collect($entries)->first(
        static fn ($entry): bool => str_contains((string) $entry->message, '[acme/paid] not booted'),
    );

    expect($skip)->not->toBeNull()
        ->and($skip->level)->toBe('warning');
});

// The release-from-the-account-page case is gone with the release actions
// themselves: seats are managed on the marketplace, never from a site's
// admin. A seat revoked server-side still locks the plugin through the
// verify path, which the tests above pin.
