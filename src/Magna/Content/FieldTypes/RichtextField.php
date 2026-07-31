<?php

declare(strict_types=1);

namespace Magna\Content\FieldTypes;

use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Schema\Blueprint;
use Magna\Content\Field;

class RichtextField extends FieldType
{
    public function typeName(): string
    {
        return 'richtext';
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
        // jsonb — see JsonField::addColumn() for why.
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
        return RichEditor::make($field->handle)
            ->label(ucwords(str_replace('_', ' ', $field->handle)))
            ->required($field->required)
            ->columnSpanFull();
    }
}
