<?php

declare(strict_types=1);

namespace Magna\Content;

use Illuminate\Support\Facades\DB;
use Magna\Content\Exceptions\DestructiveChangeException;
use Magna\Content\Models\ContentTypeRecord;

class SchemaSyncer
{
    public function __construct(
        private readonly TableGenerator $generator,
        private readonly SchemaDiffer $differ,
    ) {}

    /**
     * Apply a single diff result.
     *
     * @throws DestructiveChangeException when destructive changes exist and $allowDestructive is false
     */
    public function sync(DiffResult $diff, SchemaRegistry $registry, bool $allowDestructive = false): void
    {
        $this->guardDestructive($diff, $allowDestructive);

        foreach ($diff->changes as $change) {
            $type = $registry->get($change->contentTypeHandle);
            if ($type === null) {
                continue;
            }

            $this->applyChange($change, $type);
        }

        // Upsert content_types records so the differ can detect future type changes.
        foreach ($registry->all() as $type) {
            ContentTypeRecord::updateOrCreate(
                ['handle' => $type->handle],
                [
                    'display_name' => $type->displayName,
                    'is_database_defined' => false,
                    'schema' => $type->toArray(),
                ],
            );
        }
    }

    /**
     * Diff and sync every registered content type.
     *
     * @throws DestructiveChangeException
     */
    public function syncAll(SchemaRegistry $registry, bool $allowDestructive = false): DiffResult
    {
        $diff = $this->differ->diffAll($registry);

        // Structural columns (hierarchy) sit outside the field differ: a type
        // that turned hierarchical after its table was created gets the
        // columns backfilled here. No-op (one hasColumn check) otherwise,
        // and never destructive — the columns are only ever added.
        foreach ($registry->all() as $type) {
            $this->generator->addHierarchyColumns($type);
        }

        if ($diff->isEmpty()) {
            return $diff;
        }

        // Refuse before any transaction is opened. Throwing this from inside
        // DB::transaction() meant the rollback ran first, and on MySQL any DDL
        // already applied in the request has implicitly committed and taken the
        // savepoint with it — so the rollback itself failed with "SAVEPOINT
        // trans2 does not exist" and that PDOException replaced the
        // DestructiveChangeException the caller was supposed to see.
        $this->guardDestructive($diff, $allowDestructive);

        // Stage 13 (S5-05): this transaction wrap is unconditionally best-effort
        // on MySQL, not just SQLite — MySQL auto-commits every DDL statement
        // (CREATE/ALTER TABLE) regardless of an open transaction, so a
        // mid-batch failure across several content types can leave earlier
        // DDL changes applied while later ones (and the schema-registry DML
        // below) roll back, producing schema drift between the physical
        // tables and content_types/SchemaRegistry. Postgres is the only
        // driver where the DDL itself is genuinely transactional. On every
        // driver, this still protects the DML (ContentTypeRecord writes in
        // sync()) from partial application even when the DDL can't be
        // rolled back — worth keeping for that alone, but callers on MySQL
        // should re-run schema diff/sync after any failure here to detect
        // and repair drift rather than assuming a clean rollback happened.
        if (DB::getDriverName() !== 'sqlite') {
            DB::transaction(function () use ($diff, $registry, $allowDestructive): void {
                $this->sync($diff, $registry, $allowDestructive);
            });
        } else {
            $this->sync($diff, $registry, $allowDestructive);
        }

        return $diff;
    }

    /**
     * Refuse a destructive diff. A pure read of $diff — no database work — so
     * it is safe to call before a transaction is opened, which is exactly
     * where it belongs.
     *
     * @throws DestructiveChangeException
     */
    private function guardDestructive(DiffResult $diff, bool $allowDestructive): void
    {
        if ($allowDestructive || ! $diff->hasDestructive()) {
            return;
        }

        $descriptions = implode('; ', array_map(
            fn (DiffChange $c): string => $c->description,
            $diff->destructive(),
        ));

        throw new DestructiveChangeException(
            "Destructive schema changes require --allow-destructive: {$descriptions}",
        );
    }

    private function applyChange(DiffChange $change, ContentType $type): void
    {
        match ($change->type) {
            DiffChangeType::CreateTable => $this->generator->createTable($type),
            DiffChangeType::AddColumn => $this->applyAddColumn($change, $type),
            DiffChangeType::RemoveColumn => $this->applyRemoveColumn($change, $type),
            DiffChangeType::ChangeColumn => $this->applyChangeColumn($change, $type),
        };
    }

    private function applyAddColumn(DiffChange $change, ContentType $type): void
    {
        $field = $type->getField($change->column ?? '');
        if ($field === null) {
            return;
        }

        $this->generator->addColumn($type, $field);
    }

    private function applyRemoveColumn(DiffChange $change, ContentType $type): void
    {
        if ($change->column === null) {
            return;
        }

        $this->generator->dropColumn($type, $change->column);
    }

    private function applyChangeColumn(DiffChange $change, ContentType $type): void
    {
        // Drop then re-add is the safest cross-driver approach for type changes.
        if ($change->column === null) {
            return;
        }

        $this->generator->dropColumn($type, $change->column);

        $field = $type->getField($change->column);
        if ($field === null) {
            return;
        }

        $this->generator->addColumn($type, $field);
    }
}
