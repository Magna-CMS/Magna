<?php

declare(strict_types=1);

namespace Magna\Content\FieldTypes;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Schema\Blueprint;
use Magna\Content\Field;

abstract class FieldType
{
    /** @param array<string, mixed> $options */
    public function __construct(protected readonly array $options = []) {}

    abstract public function typeName(): string;

    abstract public function isJsonColumn(): bool;

    abstract public function isRelationOnly(): bool;

    abstract public function addColumn(Blueprint $table, string $column): void;

    /**
     * Laravel validation rules for this field type — rule strings or rule
     * objects, consumed verbatim by SchemaValidator.
     *
     * @return list<string|ValidationRule>
     */
    abstract public function validationRules(): array;

    abstract public function cast(): ?string;

    /**
     * Transform a validated value before persistence (e.g. sanitization,
     * normalization). Runs on every EntryManager write path. Default is
     * identity — override only when the stored representation must differ
     * from the validated input.
     */
    public function prepareForStorage(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Return the Filament form component for this field type.
     * Subclasses override to provide the most appropriate control.
     * The default fallback is a plain TextInput.
     */
    public function toFilamentComponent(Field $field): Component
    {
        return TextInput::make($field->handle)
            ->label(ucwords(str_replace('_', ' ', $field->handle)))
            ->required($field->required);
    }

    protected function boolOption(string $key): bool
    {
        return (bool) ($this->options[$key] ?? false);
    }

    protected function stringOption(string $key, string $default = ''): string
    {
        $val = $this->options[$key] ?? $default;

        return is_string($val) ? $val : $default;
    }
}
