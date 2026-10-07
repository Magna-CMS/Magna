<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The Pages plugin was renamed magna/pages -> magna-cms/pages so its manifest
// name matches its Composer package (the name the marketplace lists it under).
// A site that enabled it under the old name has a stale plugins.name row; the
// migration carries it across so the panel, the doctor and the update catalog
// keep recognising the plugin. Mirrors rename_docs_plugin_identity.

function pagesIdentityMigration(): object
{
    // Plain require re-executes the file (not require_once), so each call
    // returns a fresh anonymous migration instance.
    return require database_path('migrations/2026_08_05_100001_rename_pages_plugin_identity.php');
}

function insertPagesPluginRow(string $name): void
{
    DB::table('plugins')->insert([
        'id' => (string) Str::ulid(),
        'name' => $name,
        'display_name' => 'Magna Pages',
        'version' => '0.1.0-alpha',
        'enabled' => true,
        'base_path' => base_path('plugins-dev/magna/pages'),
        'manifest' => json_encode(['name' => $name]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('carries a pre-rename install from magna/pages to magna-cms/pages', function (): void {
    DB::table('plugins')->delete();
    insertPagesPluginRow('magna/pages');

    pagesIdentityMigration()->up();

    expect(DB::table('plugins')->where('name', 'magna/pages')->exists())->toBeFalse()
        ->and(DB::table('plugins')->where('name', 'magna-cms/pages')->exists())->toBeTrue();
});

it('drops the stale old-name row when the new name already exists', function (): void {
    DB::table('plugins')->delete();
    insertPagesPluginRow('magna/pages');
    insertPagesPluginRow('magna-cms/pages');

    pagesIdentityMigration()->up();

    expect(DB::table('plugins')->where('name', 'magna/pages')->exists())->toBeFalse()
        ->and(DB::table('plugins')->where('name', 'magna-cms/pages')->count())->toBe(1);
});

it('restores the old name on rollback', function (): void {
    DB::table('plugins')->delete();
    insertPagesPluginRow('magna-cms/pages');

    pagesIdentityMigration()->down();

    expect(DB::table('plugins')->where('name', 'magna-cms/pages')->exists())->toBeFalse()
        ->and(DB::table('plugins')->where('name', 'magna/pages')->exists())->toBeTrue();
});
