<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Pages plugin's manifest named it "magna/pages" while its Composer
     * package — the name the marketplace lists it under and `composer
     * require` installs — is "magna-cms/pages". The two must be the same
     * string: the marketplace installer verifies the installed manifest
     * against the listing and rolled every install of the mismatch back,
     * and update checks report installed plugins by manifest name, so the
     * catalog never matched them either.
     *
     * The manifest now says magna-cms/pages. This carries installs that
     * enabled it under the old name across the rename. Guarded: if a row
     * already exists under the new name (fresh install after the rename),
     * the old row is simply removed rather than colliding with it.
     */
    public function up(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        $newExists = DB::table('plugins')->where('name', 'magna-cms/pages')->exists();

        if ($newExists) {
            DB::table('plugins')->where('name', 'magna/pages')->delete();

            return;
        }

        DB::table('plugins')->where('name', 'magna/pages')->update(['name' => 'magna-cms/pages']);
    }

    public function down(): void
    {
        if (Schema::hasTable('plugins')) {
            DB::table('plugins')->where('name', 'magna-cms/pages')->update(['name' => 'magna/pages']);
        }
    }
};
