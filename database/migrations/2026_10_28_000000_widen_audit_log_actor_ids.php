<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs was born with string(26) actor/subject ids — sized for Magna's
 * own ULID users. A plugin session guard whose users carry UUIDs (36 chars)
 * overflows that on the login audit insert, after the session is already
 * written: the person sees a server error, refreshes, and is signed in.
 * 64 leaves room for any reasonable key shape.
 *
 * Safe over a column already widened by hand (production lekha1 was altered
 * to varchar(64) directly): change() to the same definition is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_id', 64)->nullable()->change();
            $table->string('subject_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_id', 26)->nullable()->change();
            $table->string('subject_id', 26)->nullable()->change();
        });
    }
};
