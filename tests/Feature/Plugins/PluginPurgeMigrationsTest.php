<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Tests\TestCase;

// Feature/Plugins is not in Pest.php's global RefreshDatabase list (some
// files there use PluginTestCase instead), so this file declares its own.
uses(TestCase::class, RefreshDatabase::class);

/**
 * Purging a plugin must return it to "never installed".
 *
 * The bug this covers: dropping a plugin's tables while leaving its rows in
 * the migrations ledger made the purge irreversible — enable() re-runs
 * migrate, every migration reads as already-applied, nothing rebuilds the
 * dropped tables, and the plugin can never be installed again on that
 * database.
 */
beforeEach(function (): void {
    $this->pluginPath = base_path('plugins-dev/testvendor/purgeable');
    File::ensureDirectoryExists($this->pluginPath.'/database/migrations');

    File::put(
        $this->pluginPath.'/database/migrations/2030_01_01_000000_create_purgeable_things_table.php',
        '<?php return new class extends Illuminate\Database\Migrations\Migration {};',
    );
});

afterEach(function (): void {
    File::deleteDirectory(base_path('plugins-dev/testvendor'));
});

function purgeableRecord(string $basePath): PluginRecord
{
    return PluginRecord::create([
        'name' => 'testvendor/purgeable',
        // display_name and version are NOT NULL on the plugins table — the
        // record cannot be built without them.
        'display_name' => 'Purgeable',
        'version' => '1.0.0',
        'base_path' => $basePath,
        'enabled' => false,
        'manifest' => [
            'name' => 'testvendor/purgeable',
            'uninstall' => ['tables' => ['purgeable_things']],
        ],
    ]);
}

it('forgets a purged plugin\'s migrations so it can be installed again', function (): void {
    purgeableRecord($this->pluginPath);

    Schema::create('purgeable_things', fn ($table) => $table->id());
    DB::table('migrations')->insert([
        'migration' => '2030_01_01_000000_create_purgeable_things_table',
        'batch' => 99,
    ]);

    app(PluginManager::class)->uninstall('testvendor/purgeable', purge: true);

    expect(Schema::hasTable('purgeable_things'))->toBeFalse()
        ->and(DB::table('migrations')
            ->where('migration', '2030_01_01_000000_create_purgeable_things_table')
            ->exists())->toBeFalse();
});

it('keeps the ledger intact when uninstalling without purge', function (): void {
    purgeableRecord($this->pluginPath);

    Schema::create('purgeable_things', fn ($table) => $table->id());
    DB::table('migrations')->insert([
        'migration' => '2030_01_01_000000_create_purgeable_things_table',
        'batch' => 99,
    ]);

    app(PluginManager::class)->uninstall('testvendor/purgeable');

    // No purge means data is preserved — table and ledger both survive.
    expect(Schema::hasTable('purgeable_things'))->toBeTrue()
        ->and(DB::table('migrations')
            ->where('migration', '2030_01_01_000000_create_purgeable_things_table')
            ->exists())->toBeTrue();

    Schema::dropIfExists('purgeable_things');
});

it('never forgets a migration that does not belong to the plugin', function (): void {
    purgeableRecord($this->pluginPath);

    // A core migration sitting in the ledger with no file in the plugin's
    // directory must survive a purge untouched.
    DB::table('migrations')->insert([
        'migration' => '2019_12_14_000001_create_personal_access_tokens_table',
        'batch' => 1,
    ]);

    app(PluginManager::class)->uninstall('testvendor/purgeable', purge: true);

    expect(DB::table('migrations')
        ->where('migration', '2019_12_14_000001_create_personal_access_tokens_table')
        ->exists())->toBeTrue();
});
