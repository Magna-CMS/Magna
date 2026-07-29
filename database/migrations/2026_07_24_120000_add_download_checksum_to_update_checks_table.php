<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SHA-256 of the release archive at download_url, published by Update
     * Manager alongside the URL — CoreUpdater verifies the downloaded file
     * against this before extracting/overlaying it onto core paths. Without
     * it, a compromised or MITM'd response could point at an arbitrary
     * (even host-allowlisted) URL with no way to detect a tampered archive.
     */
    public function up(): void
    {
        Schema::table('update_checks', function (Blueprint $table): void {
            $table->string('download_sha256', 64)->nullable()->after('download_url');
        });
    }

    public function down(): void
    {
        Schema::table('update_checks', function (Blueprint $table): void {
            $table->dropColumn('download_sha256');
        });
    }
};
