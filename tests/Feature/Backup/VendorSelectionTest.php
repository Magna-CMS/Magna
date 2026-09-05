<?php

declare(strict_types=1);

use Magna\Backup\VendorSelection;

/*
|--------------------------------------------------------------------------
| A backup that leaves the plugins behind is not a backup
|--------------------------------------------------------------------------
|
| vendor/ was excluded whole. Right for the framework and every library — big,
| and reproducible from composer.lock. Wrong for the installed plugins, which
| live at vendor/{vendor}/{package}: private, not on Packagist, and a zip
| install has no Composer to fetch them with. A site restored from such a
| backup came back without its ERP and DMS — the database still described their
| tables and the code that reads them was gone.
*/

function vendorEntries(string ...$names): array
{
    return array_map(static fn (string $name): string => '/srv/app/vendor/'.$name, $names);
}

it('keeps the namespace an installed plugin lives in', function (): void {
    $exclude = VendorSelection::toExclude(
        '/srv/app/vendor',
        vendorEntries('laravel', 'filament', 'roya', 'composer'),
        ['/srv/app/vendor/roya/erp'],
    );

    expect($exclude)->toContain('/srv/app/vendor/laravel')
        ->and($exclude)->toContain('/srv/app/vendor/filament')
        // The whole point: the plugin's code survives the backup.
        ->and($exclude)->not->toContain('/srv/app/vendor/roya');
});

it('keeps two plugins sharing a namespace on one entry', function (): void {
    $exclude = VendorSelection::toExclude(
        '/srv/app/vendor',
        vendorEntries('roya', 'laravel'),
        ['/srv/app/vendor/roya/erp', '/srv/app/vendor/roya/dms'],
    );

    expect($exclude)->toBe(['/srv/app/vendor/laravel']);
});

it('always keeps composer, which a restored plugin cannot load without', function (): void {
    // The autoload maps and installed.json live there, and nothing on a server
    // without Composer could regenerate them.
    $exclude = VendorSelection::toExclude('/srv/app/vendor', vendorEntries('composer', 'laravel'), []);

    expect($exclude)->toBe(['/srv/app/vendor/laravel']);
});

it('ignores a plugin that is not under vendor at all', function (): void {
    // A development install keeps its plugins in plugins-dev/, which
    // base_path() already sweeps — nothing for this to say about them.
    $exclude = VendorSelection::toExclude(
        '/srv/app/vendor',
        vendorEntries('laravel'),
        ['/srv/app/plugins-dev/roya/erp'],
    );

    expect($exclude)->toBe(['/srv/app/vendor/laravel']);
});

it('excludes everything but composer when no plugin is installed', function (): void {
    $exclude = VendorSelection::toExclude('/srv/app/vendor', vendorEntries('laravel', 'composer', 'spatie'), []);

    expect($exclude)->toBe(['/srv/app/vendor/laravel', '/srv/app/vendor/spatie']);
});

it('reads a Windows path the same as a POSIX one', function (): void {
    // The paths arrive from glob() and from a plugin manifest, and on Windows
    // those disagree about which slash they use.
    $exclude = VendorSelection::toExclude(
        'C:\\app\\vendor',
        ['C:\\app\\vendor\\laravel', 'C:\\app\\vendor\\roya'],
        ['C:\\app\\vendor\\roya\\erp'],
    );

    expect($exclude)->toBe(['C:\\app\\vendor\\laravel']);
});
