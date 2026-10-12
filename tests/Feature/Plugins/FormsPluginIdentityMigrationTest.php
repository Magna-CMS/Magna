<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The Forms plugin was renamed magna/forms -> magna-cms/forms so its manifest
// name matches its Composer package (the name the marketplace lists it under).
// A site that enabled it under the old name has a stale plugins.name row; the
// migration carries it across so the panel, the doctor and the update catalog
// keep recognising the plugin. Mirrors rename_pages_plugin_identity.

function formsIdentityMigration(): object
{
    // Plain require re-executes the file (not require_once), so each call
    // returns a fresh anonymous migration instance.
    return require database_path('migrations/2026_10_12_100000_rename_forms_plugin_identity.php');
}

function insertFormsPluginRow(string $name): void
{
    DB::table('plugins')->insert([
        'id' => (string) Str::ulid(),
        'name' => $name,
        'display_name' => 'Magna Forms',
        'version' => '0.1.0',
        'enabled' => true,
        'base_path' => base_path('plugins-dev/magna/forms'),
        'manifest' => json_encode(['name' => $name]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('carries a pre-rename install from magna/forms to magna-cms/forms', function (): void {
    DB::table('plugins')->delete();
    insertFormsPluginRow('magna/forms');

    formsIdentityMigration()->up();

    expect(DB::table('plugins')->where('name', 'magna/forms')->exists())->toBeFalse()
        ->and(DB::table('plugins')->where('name', 'magna-cms/forms')->exists())->toBeTrue();
});

it('drops the stale old-name row when the new name already exists', function (): void {
    DB::table('plugins')->delete();
    insertFormsPluginRow('magna/forms');
    insertFormsPluginRow('magna-cms/forms');

    formsIdentityMigration()->up();

    expect(DB::table('plugins')->where('name', 'magna/forms')->exists())->toBeFalse()
        ->and(DB::table('plugins')->where('name', 'magna-cms/forms')->count())->toBe(1);
});

it('restores the old name on rollback', function (): void {
    DB::table('plugins')->delete();
    insertFormsPluginRow('magna-cms/forms');

    formsIdentityMigration()->down();

    expect(DB::table('plugins')->where('name', 'magna-cms/forms')->exists())->toBeFalse()
        ->and(DB::table('plugins')->where('name', 'magna/forms')->exists())->toBeTrue();
});
