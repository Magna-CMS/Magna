<?php

declare(strict_types=1);

namespace Magna\Blocks\Conditions;

/**
 * Every display condition enabled plugins expose, keyed by handle. Same shape
 * as DynamicTagRegistry: plugins contribute at enable-time through the
 * RegistersDisplayConditions contract; consumers (the evaluator, the builder's
 * condition picker) only ever read.
 *
 * A handle that is not here is not a condition this installation can evaluate,
 * and the evaluator treats it accordingly — hidden, and uncacheable. That is
 * also what happens to a condition left behind by a plugin somebody disabled,
 * which is the safe direction for it to fail in.
 */
final class DisplayConditionRegistry
{
    /** @var array<string, DisplayCondition> */
    private array $conditions = [];

    public function register(DisplayCondition $condition): void
    {
        $this->conditions[$condition->handle()] = $condition;
    }

    public function get(string $handle): ?DisplayCondition
    {
        return $this->conditions[$handle] ?? null;
    }

    public function has(string $handle): bool
    {
        return isset($this->conditions[$handle]);
    }

    /** @return array<string, DisplayCondition> */
    public function all(): array
    {
        return $this->conditions;
    }
}
