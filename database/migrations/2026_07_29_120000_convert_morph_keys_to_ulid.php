<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the polymorphic key columns from bigint to ULID.
 *
 * Every authenticatable in Magna keys on a ULID, so `morphs()` gave the token
 * and notification tables a key column that cannot hold the value it is meant
 * to store. SQLite's dynamic typing accepted it and hid the mistake; MySQL and
 * PostgreSQL reject the insert ("invalid input syntax for type bigint"), which
 * means API tokens and database notifications were unusable on every real
 * server.
 *
 * On those servers no valid row can exist, so there is nothing to carry over.
 * A SQLite install does hold real ULIDs here — its values are copied across
 * rather than dropped, so existing API tokens keep working.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string}> table => morph name */
    private const MORPHS = [
        ['personal_access_tokens', 'tokenable'],
        ['notifications', 'notifiable'],
    ];

    public function up(): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        foreach (self::MORPHS as [$table, $morph]) {
            $column = $morph.'_id';
            $staging = $column.'_ulid';
            $index = "{$table}_{$morph}_type_{$column}_index";

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (! in_array(Schema::getColumnType($table, $column), ['bigint', 'biginteger', 'integer', 'int', 'int8', 'int4'], true)) {
                continue; // already a ULID column
            }

            // An earlier attempt that stopped part-way leaves the staging column
            // behind. Starting from a clean one is safe — nothing reads it, and
            // it is refilled from the live column immediately below.
            if (Schema::hasColumn($table, $staging)) {
                Schema::table($table, function (Blueprint $blueprint) use ($staging): void {
                    $blueprint->dropColumn($staging);
                });
            }

            Schema::table($table, function (Blueprint $blueprint) use ($staging): void {
                $blueprint->char($staging, 26)->nullable();
            });

            if ($sqlite) {
                DB::table($table)->update([$staging => DB::raw($column)]);
            }

            // SQLite refuses to drop a column that an index still names, and
            // fails the whole migration when one does. The morph index comes off
            // first and is rebuilt over the new column at the end; MySQL and
            // PostgreSQL would have rebuilt it themselves, but doing it
            // explicitly keeps one code path for all three.
            if (Schema::hasIndex($table, $index)) {
                Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                    $blueprint->dropIndex($index);
                });
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropColumn($column);
            });

            Schema::table($table, function (Blueprint $blueprint) use ($column, $staging): void {
                $blueprint->renameColumn($staging, $column);
            });

            Schema::table($table, function (Blueprint $blueprint) use ($morph, $column, $index): void {
                $blueprint->index([$morph.'_type', $column], $index);
            });
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: reverting would truncate every ULID it
        // holds back into an integer column that cannot represent it.
    }
};
