<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ed25519 signature over the release checksum, published by Update Manager
 * alongside `zip_sha256`.
 *
 * The checksum on its own only defeats an attacker who can swap the archive
 * but not the `/updates` response. Whoever controls that response controls
 * both values — and the archive is overlaid onto `src/Magna`, `app/`, and
 * `bootstrap/`. The signature is verified against the public key baked into
 * the build, which the update server never holds, so a forged response fails
 * even when it is internally consistent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('update_checks', function (Blueprint $table): void {
            $table->string('download_sha256_signature', 255)->nullable()->after('download_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('update_checks', function (Blueprint $table): void {
            $table->dropColumn('download_sha256_signature');
        });
    }
};
