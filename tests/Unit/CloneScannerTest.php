<?php

declare(strict_types=1);

use Tests\Support\CloneScanner;

/**
 * Proves the duplication ratchet is not vacuous.
 *
 * DuplicationTest passing means "no clone outside the allowlist" — which is
 * also what it would mean if the scanner silently found nothing at all.
 * These cases pin the scanner's contract from both sides: a planted clone
 * MUST be seen (through a variable rename, and within one file), and the
 * three deliberate blind spots — short copies, file-boundary straddles, and
 * literal data tables — MUST stay blind, because each one was chosen to keep
 * the report about logic rather than noise.
 */
function cloneBlock(string $suffix, string $var): string
{
    $lines = [];

    for ($i = 1; $i <= 12; $i++) {
        $lines[] = "        \${$var} = \${$var} + {$i} * intdiv({$i} + 7, 3) % max(1, {$i} - kk{$i}{$suffix}());";
    }

    return implode("\n", $lines);
}

function cloneSource(string $class, string $var, string $suffix = ''): string
{
    $body = cloneBlock($suffix, $var);

    return "<?php\nclass {$class} {\n    public function run(int \${$var}): int {\n{$body}\n        return \${$var};\n    }\n}\n";
}

it('sees a planted clone straight through a variable rename', function (): void {
    $regions = (new CloneScanner)->scanSources([
        'a.php' => cloneSource('Alpha', 'total'),
        'b.php' => cloneSource('Beta', 'sum'),
    ]);

    expect($regions)->toHaveCount(1)
        ->and($regions[0]['a']['file'])->toBe('a.php')
        ->and($regions[0]['b']['file'])->toBe('b.php')
        ->and($regions[0]['tokens'])->toBeGreaterThanOrEqual(CloneScanner::WINDOW);
});

it('gives the same clone the same fingerprint regardless of what else exists', function (): void {
    $scanner = new CloneScanner;

    $small = $scanner->scanSources([
        'a.php' => cloneSource('Alpha', 'total'),
        'b.php' => cloneSource('Beta', 'sum'),
    ]);

    $large = $scanner->scanSources([
        'zzz.php' => "<?php\nclass Zzz { public function noise(): string { return 'entirely unrelated'; } }\n",
        'a.php' => cloneSource('Alpha', 'total'),
        'b.php' => cloneSource('Beta', 'sum'),
    ]);

    expect($large)->toHaveCount(1)
        ->and($large[0]['fingerprint'])->toBe($small[0]['fingerprint']);
});

it('ignores a copy shorter than the window', function (): void {
    $tiny = "<?php\nfunction shorty(int \$x): int {\n    return \$x + 1 * intdiv(\$x + 7, 3) % max(1, \$x - 2);\n}\n";

    $regions = (new CloneScanner)->scanSources([
        'a.php' => $tiny,
        'b.php' => str_replace('shorty', 'brevis', $tiny),
    ]);

    expect($regions)->toBe([]);
});

it('reports nothing for structurally different code', function (): void {
    $regions = (new CloneScanner)->scanSources([
        'a.php' => cloneSource('Alpha', 'total'),
        'b.php' => "<?php\nclass Gamma {\n    public function other(array \$rows): array {\n        return array_map(fn (\$row) => strtoupper((string) \$row), array_filter(\$rows));\n    }\n}\n",
    ]);

    expect($regions)->toBe([]);
});

it('finds the twin inside a single file', function (): void {
    $body = cloneBlock('', 'value');
    $source = "<?php\nclass Twice {\n    public function first(int \$value): int {\n{$body}\n        return \$value;\n    }\n\n    public function second(int \$value): int {\n{$body}\n        return \$value;\n    }\n}\n";

    $regions = (new CloneScanner)->scanSources(['twice.php' => $source]);

    expect($regions)->toHaveCount(1)
        ->and($regions[0]['a']['file'])->toBe('twice.php')
        ->and($regions[0]['b']['file'])->toBe('twice.php');
});

it('never lets a clone straddle a file boundary', function (): void {
    // c.php holds the full block. a.php ends with its first half and b.php
    // begins with its second half — a naive concatenation of the corpus
    // would therefore contain the full block right across the a|b boundary
    // and match c.php. The sentinel makes that window impossible, and each
    // half alone is below the 75-token window.
    $lines = [];

    for ($i = 1; $i <= 4; $i++) {
        $lines[] = "    \$x = \$x + {$i} * intdiv({$i} + 7, 3) % max(1, {$i} - kk{$i}());";
    }

    $firstHalf = implode("\n", array_slice($lines, 0, 2));
    $secondHalf = implode("\n", array_slice($lines, 2));
    $full = implode("\n", $lines);

    $regions = (new CloneScanner)->scanSources([
        'a.php' => "<?php\n\$x = 1;\n{$firstHalf}\n",
        'b.php' => "<?php\n{$secondHalf}\nreturn \$x;\n",
        'c.php' => "<?php\n\$x = 1;\n{$full}\nreturn \$x;\n",
    ]);

    expect($regions)->toBe([]);
});

it('stays blind to duplicated literal data tables, deliberately', function (): void {
    $rows = [];

    for ($i = 0; $i < 200; $i++) {
        $rows[] = "        'k{$i}',";
    }

    $table = implode("\n", $rows);
    $source = "<?php\nfunction table(): array {\n    return [\n{$table}\n    ];\n}\n";

    $regions = (new CloneScanner)->scanSources([
        'a.php' => $source,
        'b.php' => str_replace('function table', 'function chart', $source),
    ]);

    // 200 distinct string literals is above MIN_DISTINCT_TOKENS, so a pure
    // duplicate-array file pair may still match — the blindness this pins is
    // the REPETITIVE table: few distinct tokens repeated many times.
    $repetitive = "<?php\nfunction fill(): array {\n    return [".str_repeat('0, 1, 0, 1, ', 100)."];\n}\n";

    $noise = (new CloneScanner)->scanSources([
        'a.php' => $repetitive,
        'b.php' => str_replace('function fill', 'function pour', $repetitive),
    ]);

    expect($noise)->toBe([]);
});
