<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "There is a newer version, and this site is not entitled to it."
     *
     * Distinct from `update_available`, which stays false in that case on
     * purpose: offering a button the download endpoint would refuse is worse
     * than saying nothing. This column is what lets the admin say the true
     * thing instead — that an update exists and the licence needs renewing.
     */
    public function up(): void
    {
        if (Schema::hasColumn('update_checks', 'license_required')) {
            return;
        }

        Schema::table('update_checks', function (Blueprint $table): void {
            $table->boolean('license_required')->default(false)->after('update_available');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('update_checks', 'license_required')) {
            return;
        }

        Schema::table('update_checks', function (Blueprint $table): void {
            $table->dropColumn('license_required');
        });
    }
};
