<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magna_media_folders', function (Blueprint $table): void {
            $table->char('id', 26);
            $table->char('parent_id', 26)->nullable()->index();
            $table->string('name', 255);
            $table->string('path', 1000);
            $table->timestamps();

            // Declared, not fluent (`->primary()` on the column), because a
            // fluent key is compiled after the commands added here — and this
            // foreign key points back at this same table. PostgreSQL then
            // rejects it: "there is no unique constraint matching given keys
            // for referenced table". SQLite inlines foreign keys and never
            // notices, so the ordering only breaks on a real server.
            $table->primary('id');
        });

        Schema::table('magna_media_folders', function (Blueprint $table): void {
            $table->foreign('parent_id')
                ->references('id')
                ->on('magna_media_folders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magna_media_folders');
    }
};
