<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Forms plugin's manifest named it "magna/forms" while its Composer
     * package — the name the marketplace lists it under and `composer
     * require` installs — is "magna-cms/forms". The two must be the same
     * string: the marketplace installer verifies the installed manifest
     * against the listing and rolls a mismatch back, and update checks
     * report installed plugins by manifest name, so the catalog never
     * matches them either.
     *
     * The manifest now says magna-cms/forms. This carries installs that
     * enabled it under the old name across the rename. Guarded: if a row
     * already exists under the new name (fresh install after the rename),
     * the old row is simply removed rather than colliding with it.
     * Mirrors rename_pages_plugin_identity.
     */
    public function up(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        $newExists = DB::table('plugins')->where('name', 'magna-cms/forms')->exists();

        if ($newExists) {
            DB::table('plugins')->where('name', 'magna/forms')->delete();

            return;
        }

        DB::table('plugins')->where('name', 'magna/forms')->update(['name' => 'magna-cms/forms']);
    }

    public function down(): void
    {
        if (Schema::hasTable('plugins')) {
            DB::table('plugins')->where('name', 'magna-cms/forms')->update(['name' => 'magna/forms']);
        }
    }
};
