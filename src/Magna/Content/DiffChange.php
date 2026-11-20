<?php

declare(strict_types=1);

namespace Magna\Content;

use Livewire\Wireable;

final class DiffChange implements Wireable
{
    public function __construct(
        public readonly DiffChangeType $type,
        public readonly string $contentTypeHandle,
        public readonly ?string $column,
        public readonly bool $destructive,
        public readonly string $description,
    ) {}

    /** @return array{type: string, contentTypeHandle: string, column: ?string, destructive: bool, description: string} */
    public function toLivewire(): array
    {
        return [
            'type' => $this->type->value,
            'contentTypeHandle' => $this->contentTypeHandle,
            'column' => $this->column,
            'destructive' => $this->destructive,
            'description' => $this->description,
        ];
    }

    /** @param array{type: string, contentTypeHandle: string, column: ?string, destructive: bool, description: string} $value */
    public static function fromLivewire($value): static
    {
        return new self(
            type: DiffChangeType::from($value['type']),
            contentTypeHandle: $value['contentTypeHandle'],
            column: $value['column'],
            destructive: $value['destructive'],
            description: $value['description'],
        );
    }
}
