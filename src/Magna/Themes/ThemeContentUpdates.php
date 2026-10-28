<?php

declare(strict_types=1);

namespace Magna\Themes;

use RuntimeException;
use Throwable;

/**
 * A theme update's content, applied the way an editor would want it: what
 * the site never touched follows the theme, what the site changed stays,
 * and the admin is told which is which (see ThemeContentUpdater).
 *
 * The base of the comparison is the OUTGOING theme's own bundles, read
 * before the new files replace them — so snapshot() has to run first, and
 * the installer does exactly that. Entries the site has edited are
 * remembered per theme until somebody picks "Use theme version" or the next
 * update supersedes the list.
 */
final class ThemeContentUpdates
{
    public function __construct(
        private readonly ThemeManager $themes,
        private readonly ?ThemeContentUpdater $updater,
    ) {}

    /** Whether updates can carry content at all (the page builder is installed). */
    public function supported(): bool
    {
        return $this->updater !== null;
    }

    /**
     * The installed theme's bundles as they are now, keyed by file name — the
     * base for the update about to replace them. Unreadable bundles are left
     * out: a bundle the site never could have applied is no base for anything.
     *
     * @return array<string, array<string, mixed>>
     */
    public function snapshot(string $themeName): array
    {
        $snapshot = [];

        foreach ($this->themes->content()->bundles($themeName) as $path) {
            try {
                $snapshot[basename($path)] = ThemeContent::read($path);
            } catch (RuntimeException) {
                continue;
            }
        }

        return $snapshot;
    }

    /**
     * Carry the freshly installed theme's content onto the site, three-way
     * against the snapshot taken before its files were replaced.
     *
     * @param  array<string, array<string, mixed>>  $before
     * @return array{updated: list<string>, added: list<string>, kept: list<string>}|null null when nothing could be applied
     */
    public function apply(string $themeName, array $before, ?string $actorId = null): ?array
    {
        if ($this->updater === null) {
            return null;
        }

        $report = ['updated' => [], 'added' => [], 'kept' => []];
        $kept = [];

        foreach ($this->themes->content()->bundles($themeName) as $path) {
            $file = basename($path);
            $result = $this->updater->update($before[$file] ?? [], ThemeContent::read($path), $actorId);

            foreach (['updated', 'added', 'kept'] as $bucket) {
                $report[$bucket] = [...$report[$bucket], ...$result[$bucket]];
            }

            if ($result['kept'] !== []) {
                $kept[$file] = $result['kept'];
            }
        }

        $this->remember($themeName, $kept);

        return $report;
    }

    /**
     * What the last update kept because the site had changed it.
     *
     * @return list<string>
     */
    public function kept(string $themeName): array
    {
        return array_merge([], ...array_values($this->recalled($themeName)));
    }

    /**
     * "Use theme version": replace kept entries with the installed theme's
     * own — all of them when $keys is null. The implementation keeps the
     * replaced version restorable.
     *
     * @param  list<string>|null  $keys
     *
     * @throws RuntimeException with a message fit to show an admin
     */
    public function useThemeVersion(string $themeName, ?array $keys, ?string $actorId = null): int
    {
        if ($this->updater === null) {
            throw new RuntimeException('Theme content needs the Magna Pages plugin, which is not enabled on this site.');
        }

        $remembered = $this->recalled($themeName);
        $paths = [];
        foreach ($this->themes->content()->bundles($themeName) as $path) {
            $paths[basename($path)] = $path;
        }

        $replaced = 0;

        foreach ($remembered as $file => $kept) {
            $chosen = $keys === null ? $kept : array_values(array_intersect($kept, $keys));

            if ($chosen === [] || ! isset($paths[$file])) {
                continue;
            }

            $replaced += $this->updater->replace(ThemeContent::read($paths[$file]), $chosen, $actorId);

            $left = array_values(array_diff($kept, $chosen));
            if ($left === []) {
                unset($remembered[$file]);
            } else {
                $remembered[$file] = $left;
            }
        }

        $this->remember($themeName, $remembered);

        return $replaced;
    }

    /**
     * One line for an admin about what an update did to the site's content.
     *
     * @param  array{updated: list<string>, added: list<string>, kept: list<string>}  $report
     */
    public static function summary(array $report): string
    {
        $parts = [];

        if ($report['updated'] !== []) {
            $parts[] = 'Updated '.self::names($report['updated']);
        }
        if ($report['added'] !== []) {
            $parts[] = 'added '.self::names($report['added']);
        }

        $line = $parts === [] ? 'The site\'s pages already match the theme.' : ucfirst(implode('; ', $parts)).'.';

        $kept = count($report['kept']);
        if ($kept > 0) {
            $line .= ' Kept '.$kept.' you edited ('.self::names($report['kept']).') — review them under Themes.';
        }

        return $line;
    }

    /** A readable label for an entry key: "page:pricing@" reads "pricing". */
    public static function label(string $key): string
    {
        $name = str_contains($key, ':') ? substr($key, strpos($key, ':') + 1) : $key;

        return rtrim($name, '@');
    }

    /** @param list<string> $keys */
    private static function names(array $keys): string
    {
        $labels = array_map(self::label(...), $keys);

        return count($labels) > 4
            ? implode(', ', array_slice($labels, 0, 4)).' and '.(count($labels) - 4).' more'
            : implode(', ', $labels);
    }

    /** @param array<string, list<string>> $kept */
    private function remember(string $themeName, array $kept): void
    {
        $path = $this->statePath($themeName);

        if ($kept === []) {
            @unlink($path);

            return;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, (string) json_encode($kept, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, list<string>> */
    private function recalled(string $themeName): array
    {
        $path = $this->statePath($themeName);

        try {
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        } catch (Throwable) {
            $decoded = null;
        }

        if (! is_array($decoded)) {
            return [];
        }

        $kept = [];
        foreach ($decoded as $file => $keys) {
            if (is_string($file) && is_array($keys)) {
                $kept[$file] = array_values(array_filter($keys, 'is_string'));
            }
        }

        return $kept;
    }

    private function statePath(string $themeName): string
    {
        // pathFor() validates the name; the state file is named after it.
        $this->themes->pathFor($themeName);

        return storage_path('app/magna-themes/kept/'.str_replace('/', '__', $themeName).'.json');
    }
}
