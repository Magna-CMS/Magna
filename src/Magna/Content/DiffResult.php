<?php

declare(strict_types=1);

namespace Magna\Content;

use Livewire\Wireable;

final class DiffResult implements Wireable
{
    /** @param list<DiffChange> $changes */
    public function __construct(public readonly array $changes = []) {}

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function hasDestructive(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->destructive) {
                return true;
            }
        }

        return false;
    }

    /** @return list<DiffChange> */
    public function destructive(): array
    {
        return array_values(array_filter($this->changes, fn (DiffChange $c): bool => $c->destructive));
    }

    /** @return list<DiffChange> */
    public function nonDestructive(): array
    {
        return array_values(array_filter($this->changes, fn (DiffChange $c): bool => ! $c->destructive));
    }

    /** @return array{changes: list<array{type: string, contentTypeHandle: string, column: ?string, destructive: bool, description: string}>} */
    public function toLivewire(): array
    {
        return [
            'changes' => array_map(
                fn (DiffChange $c): array => $c->toLivewire(),
                $this->changes,
            ),
        ];
    }

    /** @param array{changes: list<array{type: string, contentTypeHandle: string, column: ?string, destructive: bool, description: string}>} $value */
    public static function fromLivewire($value): static
    {
        return new self(array_map(
            fn (array $c): DiffChange => DiffChange::fromLivewire($c),
            $value['changes'],
        ));
    }
}
