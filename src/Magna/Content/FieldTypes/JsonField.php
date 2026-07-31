<?php

declare(strict_types=1);

namespace Magna\Content\FieldTypes;

use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Schema\Blueprint;
use Magna\Content\Field;

class JsonField extends FieldType
{
    public function typeName(): string
    {
        return 'json';
    }

    public function isJsonColumn(): bool
    {
        return true;
    }

    public function isRelationOnly(): bool
    {
        return false;
    }

    public function addColumn(Blueprint $table, string $column): void
    {
        // jsonb, not json: Postgres only ships GIN operator classes for jsonb,
        // and TableGenerator puts a GIN index on every JSON column. On MySQL
        // and SQLite the two are the same column type, so this is a
        // Postgres-only change.
        $table->jsonb($column)->nullable();
    }

    /** @return list<string> */
    public function validationRules(): array
    {
        return ['array'];
    }

    public function cast(): ?string
    {
        return 'array';
    }

    public function toFilamentComponent(Field $field): Component
    {
        return Textarea::make($field->handle)
            ->label(ucwords(str_replace('_', ' ', $field->handle)))
            ->required($field->required)
            ->rows(6)
            ->hint('JSON')
            ->columnSpanFull();
    }
}
