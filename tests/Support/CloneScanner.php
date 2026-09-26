<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Token-level clone detector behind the duplication guardrail.
 *
 * Finds stretches of code that are the same program text twice — identical
 * token streams once whitespace, comments and variable NAMES are set aside
 * (a copy-paste survives renaming its locals; that is usually the only edit
 * it gets). Identifiers, strings and numbers stay literal on purpose:
 * normalising those makes every pair of config-shaped methods "identical"
 * and drowns the report in noise.
 *
 * Method: each file is tokenized and normalised, every token interned to an
 * int through one shared dictionary, and the whole corpus concatenated with
 * a unique sentinel between files so no window can straddle a boundary.
 * Every WINDOW-token slice is hashed (xxh3 over the packed ints) into one
 * map; a second sighting of a hash is a match, matches on the same diagonal
 * (constant offset difference) merge into regions, and each region reports
 * both locations plus a fingerprint. The fingerprint hashes the region's
 * normalised token TEXT, not its interned ids, so it is stable no matter
 * which other files exist in the corpus — an allowlist entry survives
 * unrelated churn and dies exactly when either copy is edited.
 *
 * Budget: the full core + plugin sweep is a few seconds and a few hundred
 * MB at peak (the window map), released before reporting. If the single
 * shared test process ever presses the suite's memory ceiling, running the
 * duplication test in its own process is the intended relief valve.
 */
final class CloneScanner
{
    /** Tokens per window: the smallest run that counts as a clone. */
    public const WINDOW = 75;

    /**
     * A window must contain at least this many DISTINCT tokens. Long literal
     * arrays and match tables repeat a handful of tokens hundreds of times;
     * they are data, not logic, and are the classic false positive.
     */
    public const MIN_DISTINCT_TOKENS = 12;

    /** @var array<string, int> normalised token text -> interned id */
    private array $dictionary = [];

    /** @var list<string> interned id -> normalised token text */
    private array $reverse = [];

    /**
     * @param  list<string>  $paths
     * @return list<array{fingerprint: string, tokens: int, a: array{file: string, from: int, to: int}, b: array{file: string, from: int, to: int}}>
     */
    public function scanFiles(array $paths): array
    {
        $sources = [];

        foreach ($paths as $path) {
            $code = @file_get_contents($path);

            if ($code === false) {
                continue;
            }

            $sources[str_replace('\\', '/', $path)] = $code;
        }

        return $this->scanSources($sources);
    }

    /**
     * @param  array<string, string>  $sources  name -> PHP source
     * @return list<array{fingerprint: string, tokens: int, a: array{file: string, from: int, to: int}, b: array{file: string, from: int, to: int}}>
     */
    public function scanSources(array $sources): array
    {
        $this->dictionary = [];
        $this->reverse = [];

        $files = [];
        $sequence = [];
        $fileOf = [];
        $lineOf = [];
        $sentinel = -1;

        foreach ($sources as $name => $code) {
            $fileIndex = count($files);
            $files[] = (string) $name;

            foreach ($this->normalise($code) as [$text, $line]) {
                $sequence[] = $this->intern($text);
                $fileOf[] = $fileIndex;
                $lineOf[] = $line;
            }

            // One unique negative id between files: no window that includes
            // it can ever repeat, so clones cannot cross file boundaries.
            $sequence[] = $sentinel--;
            $fileOf[] = $fileIndex;
            $lineOf[] = 0;
        }

        $total = count($sequence);

        if ($total < self::WINDOW) {
            return [];
        }

        $packed = pack('l*', ...$sequence);
        $bytesPerWindow = self::WINDOW * 4;

        // Sliding distinct-token count, updated in O(1) per step.
        $counts = [];
        $distinct = 0;
        $lastSentinel = -1;

        for ($i = 0; $i < self::WINDOW; $i++) {
            $id = $sequence[$i];

            if ($id < 0) {
                $lastSentinel = $i;
            }

            if (($counts[$id] = ($counts[$id] ?? 0) + 1) === 1) {
                $distinct++;
            }
        }

        /** @var array<string, list<int>> $seen window hash -> every prior global offset */
        $seen = [];

        /** @var array<string, list<int>> $matches "fileA|fileB|delta" -> list of first-copy offsets */
        $matches = [];

        $limit = $total - self::WINDOW;

        for ($offset = 0; $offset <= $limit; $offset++) {
            if ($offset > 0) {
                $leaving = $sequence[$offset - 1];

                if (--$counts[$leaving] === 0) {
                    $distinct--;
                    unset($counts[$leaving]);
                }

                $entering = $sequence[$offset + self::WINDOW - 1];

                if ($entering < 0) {
                    $lastSentinel = $offset + self::WINDOW - 1;
                }

                if (($counts[$entering] = ($counts[$entering] ?? 0) + 1) === 1) {
                    $distinct++;
                }
            }

            if ($lastSentinel >= $offset || $distinct < self::MIN_DISTINCT_TOKENS) {
                continue;
            }

            $hash = hash('xxh3', substr($packed, $offset * 4, $bytesPerWindow), true);

            // Pair with EVERY earlier sighting, not only the first. A clone
            // family of three files must report all three pairs in full;
            // first-copy-only pairing let a third file claim part of the
            // overlap, so a pair's region — and its fingerprint — changed
            // with which OTHER files were in the corpus. CI (no plugins-dev)
            // and a local scan then disagreed about the same two files.
            foreach ($seen[$hash] ?? [] as $first) {
                $fileA = $fileOf[$first];
                $fileB = $fileOf[$offset];

                // Overlapping self-match of a repetitive run, not a clone.
                if ($fileA === $fileB && ($offset - $first) < self::WINDOW) {
                    continue;
                }

                $matches[$fileA.'|'.$fileB.'|'.($offset - $first)][] = $first;
            }

            $seen[$hash][] = $offset;
        }

        unset($seen, $counts, $packed);

        return $this->mergeIntoRegions($matches, $sequence, $files, $fileOf, $lineOf);
    }

    /**
     * @param  array<string, list<int>>  $matches
     * @param  list<int>  $sequence
     * @param  list<string>  $files
     * @param  list<int>  $fileOf
     * @param  list<int>  $lineOf
     * @return list<array{fingerprint: string, tokens: int, a: array{file: string, from: int, to: int}, b: array{file: string, from: int, to: int}}>
     */
    private function mergeIntoRegions(array $matches, array $sequence, array $files, array $fileOf, array $lineOf): array
    {
        $regions = [];

        foreach ($matches as $diagonal => $offsets) {
            $delta = (int) substr((string) $diagonal, strrpos((string) $diagonal, '|') + 1);

            sort($offsets);

            $flush = function (int $start, int $end) use (&$regions, $delta, $sequence, $files, $fileOf, $lineOf): void {
                $length = ($end - $start) + self::WINDOW;

                $words = [];

                for ($i = $start; $i < $start + $length; $i++) {
                    $words[] = $this->reverse[$sequence[$i]];
                }

                $regions[] = [
                    'fingerprint' => substr(hash('xxh3', implode("\x1f", $words)), 0, 12),
                    'tokens' => $length,
                    'a' => [
                        'file' => $files[$fileOf[$start]],
                        'from' => $lineOf[$start],
                        'to' => $lineOf[$start + $length - 1],
                    ],
                    'b' => [
                        'file' => $files[$fileOf[$start + $delta]],
                        'from' => $lineOf[$start + $delta],
                        'to' => $lineOf[$start + $delta + $length - 1],
                    ],
                ];
            };

            $runStart = $offsets[0];
            $previous = $offsets[0];

            foreach (array_slice($offsets, 1) as $offset) {
                if ($offset === $previous + 1) {
                    $previous = $offset;

                    continue;
                }

                $flush($runStart, $previous);
                $runStart = $offset;
                $previous = $offset;
            }

            $flush($runStart, $previous);
        }

        usort($regions, fn (array $x, array $y): int => [$x['a']['file'], $x['a']['from']] <=> [$y['a']['file'], $y['a']['from']]);

        return $regions;
    }

    /**
     * @return list<array{0: string, 1: int}> normalised token text + line
     */
    private function normalise(string $code): array
    {
        $out = [];
        $line = 1;

        foreach (@token_get_all($code) as $token) {
            if (is_array($token)) {
                [$id, $text, $line] = $token;

                if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_OPEN_TAG || $id === T_CLOSE_TAG) {
                    continue;
                }

                // A copy-paste survives renaming its locals; that rename must
                // not hide it.
                $out[] = [$id === T_VARIABLE ? '$V' : $text, $line];

                continue;
            }

            $out[] = [$token, $line];
        }

        return $out;
    }

    private function intern(string $text): int
    {
        $id = $this->dictionary[$text] ?? null;

        if ($id !== null) {
            return $id;
        }

        $id = count($this->reverse);
        $this->dictionary[$text] = $id;
        $this->reverse[] = $text;

        return $id;
    }
}
