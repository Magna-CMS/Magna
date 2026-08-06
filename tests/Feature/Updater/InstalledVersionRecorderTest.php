<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Updater\InstalledVersionRecorder;
use Magna\Updater\UpdateCheck;
use Tests\TestCase;

/**
 * The Plugins page and the dashboard badge read `update_checks`, which only the
 * 12-hourly check-in used to write. Installing an update never touched it, so a
 * site that had just updated went on advertising the same version — with an
 * Update button that would fetch what was already on disk. For a paid plugin
 * that reads as a licence problem rather than a stale row.
 */
uses(TestCase::class, RefreshDatabase::class);

function pluginCheck(string $slug, string $current, ?string $latest, bool $available = true, bool $licenseRequired = false): UpdateCheck
{
    return UpdateCheck::query()->create([
        'type' => 'plugin',
        'slug' => $slug,
        'current_version' => $current,
        'latest_version' => $latest,
        'update_available' => $available,
        'license_required' => $licenseRequired,
        'checked_at' => now()->subHours(6),
    ]);
}

it('clears the notification once the advertised version is installed', function (): void {
    $check = pluginCheck('roya/erp', '1.0.2', '1.0.4');

    app(InstalledVersionRecorder::class)->record('roya/erp', '1.0.4');

    $check->refresh();
    expect($check->current_version)->toBe('1.0.4');
    expect($check->update_available)->toBeFalse();
});

// A site can be two releases behind, so this answers "is there still something
// newer than what is now on disk" rather than blindly clearing the flag.
it('keeps the notification when a newer version still exists', function (): void {
    $check = pluginCheck('roya/dms', '1.0.4', '1.0.6');

    app(InstalledVersionRecorder::class)->record('roya/dms', '1.0.5');

    $check->refresh();
    expect($check->current_version)->toBe('1.0.5');
    expect($check->update_available)->toBeTrue();
});

// license_required is the "a newer version exists that your licence does not
// cover" flag. Once bytes are on disk it no longer describes anything true.
it('clears a licence-blocked flag for the version that landed', function (): void {
    $check = pluginCheck('roya/erp', '1.0.2', '1.0.4', available: false, licenseRequired: true);

    app(InstalledVersionRecorder::class)->record('roya/erp', '1.0.4');

    $check->refresh();
    expect($check->license_required)->toBeFalse();
    expect($check->update_available)->toBeFalse();
});

it('does nothing for a plugin the check-in has never seen', function (): void {
    app(InstalledVersionRecorder::class)->record('acme/unknown', '2.0.0');

    expect(UpdateCheck::query()->where('slug', 'acme/unknown')->exists())->toBeFalse();
});

// The core row is a different thing entirely and must not be touched by a
// plugin install that happens to share a version string.
it('leaves the core row alone', function (): void {
    $core = UpdateCheck::query()->create([
        'type' => 'core',
        'current_version' => '1.3.5',
        'latest_version' => '1.3.6',
        'update_available' => true,
        'checked_at' => now(),
    ]);

    app(InstalledVersionRecorder::class)->record('roya/erp', '1.3.6');

    $core->refresh();
    expect($core->update_available)->toBeTrue();
    expect($core->current_version)->toBe('1.3.5');
});
