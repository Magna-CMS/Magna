<?php

declare(strict_types=1);

namespace Magna\Content;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TableGenerator
{
    private const FIXED_COLUMNS = [
        'id', 'status', 'locale', 'published_at', 'unpublish_at', 'author_id', 'draft_of', 'created_at', 'updated_at',
    ];

    public function createTable(ContentType $type): void
    {
        $tableName = $type->tableName();

        Schema::create($tableName, function (Blueprint $table) use ($type): void {
            $table->ulid('id')->primary();
            // Indexed: every list page (EntryResource) filters by status/locale
            // and the scheduler widget filters/orders by published_at/unpublish_at.
            $table->string('status', 20)->default('draft')->index();
            $table->string('locale', 10)->default('')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('unpublish_at')->nullable()->index();
            $table->char('author_id', 26)->nullable();
            $table->char('draft_of', 26)->nullable()->index();
            $table->timestamps();
            // EntryResource default-sorts by updated_at desc.
            $table->index('updated_at');

            foreach ($type->columnFields() as $field) {
                $field->type->addColumn($table, $field->handle);
            }
        });

        $this->addGinIndexes($type);
    }

    public function dropTable(ContentType $type): void
    {
        Schema::dropIfExists($type->tableName());
    }

    /**
     * Backfill the status/locale/published_at/unpublish_at/updated_at indexes
     * onto a content-type table created before these were added to
     * createTable(). Safe to run repeatedly — each index is added individually
     * so an already-existing one is skipped without aborting the rest.
     */
    public function addPerformanceIndexes(ContentType $type): void
    {
        $tableName = $type->tableName();

        if (! Schema::hasTable($tableName)) {
            return;
        }

        foreach (['status', 'locale', 'published_at', 'unpublish_at', 'updated_at'] as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                continue;
            }

            try {
                Schema::table($tableName, function (Blueprint $table) use ($column): void {
                    $table->index($column);
                });
            } catch (\Throwable $e) {
                // Duplicate/already-exists errors are expected on re-runs across
                // MySQL/Postgres/SQLite (each phrases it differently) — anything
                // else should surface.
                if (! preg_match('/already exists|duplicate/i', $e->getMessage())) {
                    throw $e;
                }
            }
        }
    }

    public function addColumn(ContentType $type, Field $field): void
    {
        Schema::table($type->tableName(), function (Blueprint $table) use ($field): void {
            $field->type->addColumn($table, $field->handle);
        });

        if ($field->type->isJsonColumn()) {
            $this->addGinIndex($type->tableName(), $type->handle, $field->handle);
        }
    }

    public function dropColumn(ContentType $type, string $column): void
    {
        Schema::table($type->tableName(), function (Blueprint $table) use ($column): void {
            $table->dropColumn($column);
        });
    }

    /** @return list<string> */
    public function dynamicColumns(ContentType $type): array
    {
        if (! Schema::hasTable($type->tableName())) {
            return [];
        }

        return array_values(array_filter(
            Schema::getColumnListing($type->tableName()),
            fn (string $col): bool => ! in_array($col, self::FIXED_COLUMNS, true),
        ));
    }

    private function addGinIndexes(ContentType $type): void
    {
        foreach ($type->jsonColumnHandles() as $handle) {
            $this->addGinIndex($type->tableName(), $type->handle, $handle);
        }
    }

    private function addGinIndex(string $tableName, string $typeHandle, string $column): void
    {
        // S1-08 defense in depth: $typeHandle/$column are validated at
        // construction (ContentType::fromArray()/Field::fromArray()), but
        // this raw DB::statement() call below has no parameter binding for
        // identifiers — re-assert the same allowlist unconditionally
        // (before the driver check, so it applies on every DB driver, not
        // just Postgres) immediately before building the SQL string, and
        // double-quote every identifier, so a future caller that skips
        // domain-layer validation can't smuggle SQL through here.
        foreach (['tableName' => $tableName, 'typeHandle' => $typeHandle, 'column' => $column] as $label => $value) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $value)) {
                throw new \InvalidArgumentException("Refusing to build GIN index SQL with an invalid {$label} \"{$value}\".");
            }
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Postgres has no default GIN operator class for `json` — only for
        // `jsonb`. Indexing a json column raises "data type json has no
        // default operator class for access method gin" and takes the whole
        // content-type creation down with it, which is why creating a type
        // with a blocks/json/richtext field never worked on Postgres. New
        // columns are jsonb (see JsonField::addColumn()); a column left as
        // json by an older install simply goes unindexed rather than making
        // the table impossible to create.
        if ($this->postgresColumnType($tableName, $column) !== 'jsonb') {
            return;
        }

        $indexName = 'idx_'.$typeHandle.'_'.$column.'_gin';
        $quotedIndex = '"'.$indexName.'"';
        $quotedTable = '"'.$tableName.'"';
        $quotedColumn = '"'.$column.'"';
        DB::statement("CREATE INDEX IF NOT EXISTS {$quotedIndex} ON {$quotedTable} USING gin ({$quotedColumn})");
    }

    /** The live Postgres data type of a column, or null if it cannot be read. */
    private function postgresColumnType(string $tableName, string $column): ?string
    {
        $row = DB::selectOne(
            'select data_type from information_schema.columns
             where table_schema = current_schema() and table_name = ? and column_name = ?',
            [$tableName, $column]
        );

        $type = is_object($row) ? ($row->data_type ?? null) : null;

        return is_string($type) ? $type : null;
    }
}
