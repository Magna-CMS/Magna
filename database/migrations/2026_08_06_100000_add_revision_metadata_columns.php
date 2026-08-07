<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revision workflow metadata (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §A4):
 *
 * - kind: why the snapshot exists (save | publish | restore_point | autosave).
 *   Existing rows default to 'save' — they are pre-change snapshots.
 * - label: user-assigned name ("before redesign"). Labeled revisions are
 *   exempt from count-based pruning so routine saves can never silently
 *   evict a milestone.
 * - schema_version: block-document schema the payload was written under,
 *   consulted by restore/browse paths once format migrations exist.
 *
 * Added now while the append-only table is small — retrofitting columns
 * after years of snapshots is the expensive path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magna_revisions', function (Blueprint $table): void {
            $table->string('kind', 32)->default('save')->after('payload');
            $table->string('label')->nullable()->after('kind');
            $table->string('schema_version', 16)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('magna_revisions', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'label', 'schema_version']);
        });
    }
};
