<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Magna\Licensing\LicenseEnforcer;
use Magna\Licensing\LicenseEntry;
use Magna\Licensing\LicenseGate;
use Magna\Licensing\LicenseState;
use Magna\Licensing\LicenseStore;
use Magna\Plugins\PluginManager;

/**
 * The behaviour the whole corporate model rests on: when the publisher ends
 * a licence, the client's site stops using the product — and says why.
 */
function cacheLicense(string $slug, string $status, string $type = 'corporate', ?Carbon $expiresAt = null, bool $autoDisabled = false): LicenseEntry
{
    $entry = new LicenseEntry(
        productSlug: $slug,
        token: str_repeat('t', 64),
        status: $status,
        licenseType: $type,
        expiresAt: $expiresAt,
        updateEntitled: $status === 'active',
        serverTime: Carbon::now(),
        lastVerifiedAt: Carbon::now(),
        autoDisabled: $autoDisabled,
    );

    app(LicenseStore::class)->put($entry);

    return $entry;
}

// ─── Gate policy ────────────────────────────────────────────────────────────

it('locks a cancelled or suspended licence regardless of type', function (): void {
    cacheLicense('acme/custom', 'revoked');
    cacheLicense('acme/shop', 'suspended', 'annual');

    $gate = app(LicenseGate::class);

    expect($gate->isLocked('acme/custom'))->toBeTrue()
        ->and($gate->isLocked('acme/shop'))->toBeTrue();
});

it('never locks a plugin that has no licence at all', function (): void {
    // Every free plugin lands here — the gate runs on each boot, so this is
    // the case that must not misfire.
    expect(app(LicenseGate::class)->isLocked('magna/blog'))->toBeFalse()
        ->and(app(LicenseGate::class)->stateOf('magna/blog'))->toBe(LicenseState::Unlicensed);
});

it('lists every locked product with a readable reason', function (): void {
    cacheLicense('acme/custom', 'revoked');
    cacheLicense('acme/trial-thing', 'expired', 'trial');
    cacheLicense('acme/fine', 'active', 'annual', Carbon::now()->addYear());

    $locked = app(LicenseGate::class)->locked();

    expect($locked)->toHaveCount(2)
        ->and($locked['acme/custom']->reason())->toContain('cancelled by the publisher')
        ->and($locked['acme/trial-thing']->reason())->toContain('free trial');
});

// ─── Enforcement ────────────────────────────────────────────────────────────

it('disables a product whose licence ended and flags that licensing did it', function (): void {
    cacheLicense('acme/custom', 'revoked');

    $plugins = Mockery::mock(PluginManager::class);
    $plugins->shouldReceive('disable')->once()->with('acme/custom');
    app()->instance(PluginManager::class, $plugins);

    $result = app(LicenseEnforcer::class)->sync();

    expect($result['locked'])->toBe(['acme/custom'])
        ->and(app(LicenseStore::class)->get('acme/custom')->autoDisabled)->toBeTrue();
});

it('does not disable the same product twice', function (): void {
    cacheLicense('acme/custom', 'revoked', autoDisabled: true);

    $plugins = Mockery::mock(PluginManager::class);
    $plugins->shouldNotReceive('disable');
    app()->instance(PluginManager::class, $plugins);

    expect(app(LicenseEnforcer::class)->sync()['locked'])->toBe([]);
});

it('re-enables a product when its licence recovers', function (): void {
    cacheLicense('acme/custom', 'active', 'corporate', Carbon::now()->addYear(), autoDisabled: true);

    $plugins = Mockery::mock(PluginManager::class);
    $plugins->shouldReceive('enable')->once()->with('acme/custom');
    app()->instance(PluginManager::class, $plugins);

    $result = app(LicenseEnforcer::class)->sync();

    expect($result['restored'])->toBe(['acme/custom'])
        ->and(app(LicenseStore::class)->get('acme/custom')->autoDisabled)->toBeFalse();
});

it('leaves a plugin an admin disabled by hand alone', function (): void {
    // Valid licence, autoDisabled false — licensing has no business here.
    cacheLicense('acme/custom', 'active', 'corporate', Carbon::now()->addYear());

    $plugins = Mockery::mock(PluginManager::class);
    $plugins->shouldNotReceive('enable');
    $plugins->shouldNotReceive('disable');
    app()->instance(PluginManager::class, $plugins);

    expect(app(LicenseEnforcer::class)->sync())->toBe(['locked' => [], 'restored' => []]);
});

it('keeps the flag set when re-enabling fails so the next pass retries', function (): void {
    cacheLicense('acme/custom', 'active', 'corporate', Carbon::now()->addYear(), autoDisabled: true);

    $plugins = Mockery::mock(PluginManager::class);
    $plugins->shouldReceive('enable')->once()->andThrow(new RuntimeException('files missing'));
    app()->instance(PluginManager::class, $plugins);

    expect(app(LicenseEnforcer::class)->sync()['restored'])->toBe([])
        ->and(app(LicenseStore::class)->get('acme/custom')->autoDisabled)->toBeTrue();
});
