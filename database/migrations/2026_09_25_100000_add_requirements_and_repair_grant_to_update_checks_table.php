<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What Update Manager may say about a release beyond where to get it.
     *
     * `requires_php` and `min_upgrade_from` are hints about the latest
     * release, so the panel can refuse before a download rather than after;
     * the archive's own manifest is what the updater enforces. The
     * `installed_*` columns are the verified archive for the version this
     * site ALREADY runs — what lets a site an older updater left incomplete
     * repair itself from the panel instead of a shell.
     *
     * Deliberately references no application class: on a site updated by a
     * pre-1.4.4 updater this runs under the previous release's framework,
     * which cannot autoload anything the new release added.
     */
    public function up(): void
    {
        Schema::table('update_checks', function (Blueprint $table): void {
            if (! Schema::hasColumn('update_checks', 'requires_php')) {
                $table->string('requires_php', 64)->nullable()->after('license_required');
            }
            if (! Schema::hasColumn('update_checks', 'min_upgrade_from')) {
                $table->string('min_upgrade_from', 32)->nullable()->after('requires_php');
            }
            if (! Schema::hasColumn('update_checks', 'installed_download_url')) {
                $table->text('installed_download_url')->nullable()->after('min_upgrade_from');
            }
            if (! Schema::hasColumn('update_checks', 'installed_download_sha256')) {
                $table->string('installed_download_sha256', 64)->nullable()->after('installed_download_url');
            }
            if (! Schema::hasColumn('update_checks', 'installed_download_sha256_signature')) {
                $table->text('installed_download_sha256_signature')->nullable()->after('installed_download_sha256');
            }
        });
    }

    public function down(): void
    {
        Schema::table('update_checks', function (Blueprint $table): void {
            foreach (['installed_download_sha256_signature', 'installed_download_sha256', 'installed_download_url', 'min_upgrade_from', 'requires_php'] as $column) {
                if (Schema::hasColumn('update_checks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
