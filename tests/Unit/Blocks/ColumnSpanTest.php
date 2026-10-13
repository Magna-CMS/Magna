<?php

declare(strict_types=1);

/**
 * A column's span survives being written as something other than an int.
 *
 * `span` came out of a JSON document, where a ratio is a float the moment
 * anything divides — and the coercion was `is_int($raw) ? $raw : 12`. So 1.05
 * was not clamped to 1 and not rounded to 1: it fell to the ABSENT-value
 * default of 12 and clamped there, turning a narrow column into a full-width
 * one. Nothing errored and the page rendered, so the only symptom was a layout
 * that looked like the theme's fault.
 *
 * Found while porting a design whose column pairs are 1.05/0.95, 1.15/0.85 and
 * 1.1/0.9 — none of which any integer twelfth expresses, and all of which
 * silently became "full width" on the way in.
 */

use Magna\Blocks\ColumnNode;

function spanOf(mixed $span): int
{
    return ColumnNode::fromArray(['id' => 'c1', 'span' => $span, 'blocks' => []])->span;
}

it('keeps an integer span as written', function (): void {
    expect(spanOf(6))->toBe(6)
        ->and(spanOf(1))->toBe(1)
        ->and(spanOf(12))->toBe(12);
});

it('rounds a fractional span instead of widening it to the full row', function (): void {
    // The regression itself: every one of these used to come back 12.
    expect(spanOf(1.05))->toBe(1)
        ->and(spanOf(0.95))->toBe(1)
        ->and(spanOf(6.4))->toBe(6)
        ->and(spanOf(6.5))->toBe(7)
        ->and(spanOf(11.6))->toBe(12);
});

it('reads a numeric string, since JSON and form state both produce them', function (): void {
    expect(spanOf('4'))->toBe(4)
        ->and(spanOf('4.6'))->toBe(5);
});

it('still clamps into the twelfths grid', function (): void {
    // Rounding happens before the clamp, so neither can be skipped by the
    // other: 0.4 rounds to 0 and the floor lifts it, 99.9 rounds past the top.
    expect(spanOf(0.4))->toBe(1)
        ->and(spanOf(0))->toBe(1)
        ->and(spanOf(-3))->toBe(1)
        ->and(spanOf(99.9))->toBe(12);
});

it('falls back to a full row for a value it cannot read', function (): void {
    // Not a number at all is no information, and the absent-value default is
    // the honest reading of none — which is also what `span` omitted means.
    expect(spanOf('wide'))->toBe(12)
        ->and(spanOf(null))->toBe(12)
        ->and(spanOf(['6']))->toBe(12)
        ->and(spanOf(true))->toBe(12);
});
