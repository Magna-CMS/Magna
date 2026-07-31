<?php

declare(strict_types=1);

namespace Magna\Content\FieldTypes;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Illuminate\Database\Schema\Blueprint;
use Magna\Blocks\Livewire\BlockEditor;
use Magna\Content\Field;

class BlocksField extends FieldType
{
    public function typeName(): string
    {
        return 'blocks';
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
        return Livewire::make(BlockEditor::class)
            ->key('block-editor-'.$field->handle)
            ->columnSpanFull();
    }
}
