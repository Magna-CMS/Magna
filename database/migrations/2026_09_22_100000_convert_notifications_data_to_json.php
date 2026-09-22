<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store notification payloads in a real JSON column.
 *
 * Filament's notification bell counts unread rows with
 * `where('data->format', 'filament')`, which compiles to the `->>` operator.
 * PostgreSQL defines that operator for `json`/`jsonb` only, so against the
 * `text` column the table shipped with, every panel request died on
 * "operator does not exist: text ->> unknown" — a fresh PostgreSQL install
 * 500s the moment the admin panel loads. MySQL tolerates the text column,
 * which is why this survived until the first PostgreSQL deployment.
 *
 * Every stored value is already a JSON document (Laravel serialises the
 * payload before writing), so the cast carries existing rows over untouched.
 * SQLite has no distinct JSON type — `json()` is `text` there — so it is left
 * alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->convertible()) {
            return;
        }

        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE notifications MODIFY data JSON NOT NULL'),
            default => null,
        };
    }

    public function down(): void
    {
        if (! $this->convertible()) {
            return;
        }

        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE notifications MODIFY data TEXT NOT NULL'),
            default => null,
        };
    }

    /**
     * SQLite maps both types onto `text`, so its column is already whatever
     * this migration would make it — and `ALTER COLUMN` is unsupported there.
     */
    private function convertible(): bool
    {
        return DB::connection()->getDriverName() !== 'sqlite'
            && Schema::hasTable('notifications')
            && Schema::hasColumn('notifications', 'data');
    }
};
