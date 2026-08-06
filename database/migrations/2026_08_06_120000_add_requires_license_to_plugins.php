<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Magna\Licensing\LicenseStore;

/**
 * Remember that a plugin arrived through the licensed download path.
 *
 * LicenseGate reads only the cached licence entry, and a product with no
 * entry is "unlicensed", which boots normally — free plugins have no entry
 * and must keep working. That made releasing a seat a way to keep a paid
 * plugin running for free: release frees the seat AND forgets the entry, so
 * the plugin carried on serving while the same key was activated on the next
 * domain. The marketplace's 3-moves-per-30-days cap does not help, because
 * every released site keeps working forever.
 *
 * The flag survives the entry being forgotten, so the gate can tell "free
 * plugin, never licensed" from "paid plugin whose licence is gone".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('plugins', 'requires_license')) {
            return;
        }

        Schema::table('plugins', function (Blueprint $table): void {
            $table->boolean('requires_license')->default(false)->after('enabled');
        });

        $this->backfillFromLicenceCache();
    }

    /**
     * Sites that installed a paid plugin before this column existed would
     * otherwise be grandfathered into the hole. Anything holding a licence
     * entry right now got here through the licensed path, so mark it.
     *
     * Best-effort: the cache is encrypted application state rather than a
     * plain table, and a migration must never fail over it.
     */
    private function backfillFromLicenceCache(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        try {
            $slugs = array_keys(app(LicenseStore::class)->all());
        } catch (Throwable) {
            return;
        }

        if ($slugs === []) {
            return;
        }

        DB::table('plugins')->whereIn('name', $slugs)->update(['requires_license' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('plugins', 'requires_license')) {
            return;
        }

        Schema::table('plugins', function (Blueprint $table): void {
            $table->dropColumn('requires_license');
        });
    }
};
