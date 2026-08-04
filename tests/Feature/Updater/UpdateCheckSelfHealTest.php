<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\MagnaServiceProvider;
use Magna\Updater\UpdateCheck;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// A core update replaces the code, but the update_checks row was computed
// against the version running BEFORE the update and the next scheduled
// check is up to 12 hours away. System Info kept offering "Update to
// vX" for a version the site was already running until a manual re-check.
// UpdateCheck::core() now self-heals the row when the running version no
// longer matches it.

function staleCoreRow(string $latest): UpdateCheck
{
    return UpdateCheck::query()->create([
        'type' => 'core',
        'slug' => null,
        'current_version' => '0.0.1', // what was running at check time
        'latest_version' => $latest,
        'update_available' => true,
        'checked_at' => now()->subHour(),
    ]);
}

it('clears the update flag once the offered version is already running', function (): void {
    staleCoreRow('v'.MagnaServiceProvider::VERSION);

    $check = UpdateCheck::core();

    expect($check->update_available)->toBeFalse()
        ->and($check->current_version)->toBe(MagnaServiceProvider::VERSION)
        ->and($check->fresh()->update_available)->toBeFalse();
});

it('keeps offering an update that really is newer than the running code', function (): void {
    staleCoreRow('99.0.0');

    $check = UpdateCheck::core();

    expect($check->update_available)->toBeTrue()
        ->and($check->current_version)->toBe(MagnaServiceProvider::VERSION);
});

it('leaves a row that matches the running version untouched', function (): void {
    $row = UpdateCheck::query()->create([
        'type' => 'core',
        'slug' => null,
        'current_version' => MagnaServiceProvider::VERSION,
        'latest_version' => MagnaServiceProvider::VERSION,
        'update_available' => false,
        'checked_at' => now(),
    ]);

    expect(UpdateCheck::core()->updated_at->equalTo($row->updated_at))->toBeTrue();
});
