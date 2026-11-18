<?php

declare(strict_types=1);

namespace Magna\Themes;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * A theme's demo content: which bundles it ships, and putting them on the
 * site — "install with demo data".
 *
 * A theme declares its demo bundles in theme.json:
 *
 *     "demoContent": ["starter/site.json"]
 *
 * Without the key every starter/*.json counts, which is what a theme that
 * predates it ships. Declaring it lets a theme keep optional bundles beside
 * the demo (Eva ships a builder-editable header and footer that should not
 * replace its own on a plain demo import).
 *
 * The bundle files are theme data, so they are held to the same suspicion as
 * any upload: only .json files inside the theme's own starter/ folder, a
 * size cap, and the importer's own validation of every row.
 */
final class ThemeContent
{
    /** A demo bundle with images inlined runs to a few MB; nothing honest needs 25. */
    private const MAX_BUNDLE_BYTES = 25 * 1024 * 1024;

    /** How long the Themes page trusts "this theme's demo is on the site" before asking again. */
    private const ON_SITE_TTL_SECONDS = 300;

    public function __construct(
        private readonly ThemeManager $themes,
        private readonly ?ThemeContentImporter $importer,
        private readonly ?ThemeContentRemover $remover = null,
    ) {}

    /** Whether this site can import demo content at all (the page builder is installed). */
    public function supported(): bool
    {
        return $this->importer !== null;
    }

    /** Whether this site can take demo content back out (the page builder is new enough). */
    public function removable(): bool
    {
        return $this->remover !== null;
    }

    /**
     * Whether any of this theme's demo content is on the site, untouched and
     * removable — what decides if the Themes page offers to remove it.
     *
     * Asking means reading every bundle (a few MB each with inlined images)
     * and comparing each row with the site, on every render of a page that
     * re-renders on each click. So the answer is cached briefly, keyed on the
     * bundles themselves, and forgotten whenever this class imports or
     * removes. A stale "yes" costs nothing: the removal re-checks every row.
     */
    public function onSite(string $themeName): bool
    {
        if (! $this->removable()) {
            return false;
        }

        $bundles = $this->bundles($themeName);
        if ($bundles === []) {
            return false;
        }

        return (bool) Cache::remember(
            self::onSiteKey($themeName, $bundles),
            self::ON_SITE_TTL_SECONDS,
            fn (): bool => $this->removalPreview($themeName)['remove'] !== [],
        );
    }

    /**
     * What removing this theme's demo content would take out, and what it
     * would leave and why, across every bundle.
     *
     * @return array{remove: list<array{key: string, label: string}>, keep: list<array{key: string, label: string, reason: string}>}
     *
     * @throws RuntimeException with a message fit to show an admin
     */
    public function removalPreview(string $themeName): array
    {
        $remove = [];
        $keep = [];

        foreach ($this->removableBundles($themeName) as $path) {
            try {
                $part = $this->remover()->preview(self::read($path));
            } catch (\InvalidArgumentException $e) {
                throw new RuntimeException(basename($path).': '.$e->getMessage(), 0, $e);
            }

            // Two bundles can ship the same row; it is one row on the site.
            foreach ($part['remove'] as $row) {
                $remove[$row['key']] = $row;
            }
            foreach ($part['keep'] as $row) {
                $keep[$row['key']] = $row;
            }
        }

        return ['remove' => array_values($remove), 'keep' => array_values($keep)];
    }

    /**
     * Remove this theme's untouched demo content from the site.
     *
     * @return array<string, int> removed per kind, plus "kept"
     *
     * @throws RuntimeException with a message fit to show an admin
     */
    public function removeDemo(string $themeName, ?string $actorId = null): array
    {
        $bundles = $this->removableBundles($themeName);
        $totals = [];

        try {
            foreach ($bundles as $path) {
                try {
                    $counts = $this->remover()->removeUntouched(self::read($path), $actorId);
                } catch (\InvalidArgumentException $e) {
                    throw new RuntimeException(basename($path).': '.$e->getMessage(), 0, $e);
                }

                foreach ($counts as $kind => $count) {
                    $totals[$kind] = ($totals[$kind] ?? 0) + (int) $count;
                }
            }
        } finally {
            Cache::forget(self::onSiteKey($themeName, $bundles));
        }

        return $totals;
    }

    /**
     * One line for an admin: what a removal took out.
     *
     * @param  array<string, int>  $totals
     */
    public static function removalSummary(array $totals): string
    {
        $labels = ['pages' => 'page', 'templates' => 'header/footer part', 'menus' => 'menu', 'patterns' => 'pattern', 'blog:posts' => 'post', 'forms:forms' => 'form'];
        $parts = [];

        foreach ($labels as $key => $noun) {
            $n = (int) ($totals[$key] ?? 0);
            if ($n > 0) {
                $parts[] = $n.' '.$noun.($n === 1 ? '' : 's');
            }
        }

        $kept = (int) ($totals['kept'] ?? 0);
        $removed = $parts === [] ? 'Nothing was removed' : 'Removed '.implode(', ', $parts);

        return $removed.($kept > 0 ? " — {$kept} kept because you changed them or they are still in use." : '.');
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException with a message fit to show an admin
     */
    private function removableBundles(string $themeName): array
    {
        $this->remover();

        $bundles = $this->bundles($themeName);
        if ($bundles === []) {
            throw new RuntimeException('This theme does not ship demo content.');
        }

        return $bundles;
    }

    private function remover(): ThemeContentRemover
    {
        return $this->remover ?? throw new RuntimeException('Removing demo content needs a newer Magna Pages plugin.');
    }

    /** @param  list<string>  $bundles */
    private static function onSiteKey(string $themeName, array $bundles): string
    {
        $stamp = array_map(static fn (string $path): string => $path.'|'.(int) @filemtime($path).'|'.(int) @filesize($path), $bundles);

        return 'magna.themes.demo-on-site.'.sha1($themeName.'|'.implode(';', $stamp));
    }

    /** Whether this installed theme ships demo content this site can import. */
    public function available(string $themeName): bool
    {
        return $this->supported() && $this->bundles($themeName) !== [];
    }

    /**
     * Absolute paths of the theme's demo bundles, in declared (or sorted) order.
     *
     * @return list<string>
     */
    public function bundles(string $themeName): array
    {
        $root = realpath($this->themes->pathFor($themeName));
        $starter = $root === false ? false : realpath($root.'/'.ThemeSiteKit::DIRECTORY);

        if ($root === false || $starter === false || ! is_dir($starter)) {
            return [];
        }

        $declared = $this->declared($root);

        $candidates = $declared ?? array_map(
            static fn (string $relative): string => $root.'/'.$relative,
            ThemeSiteKit::bundles($root),
        );

        $paths = [];
        foreach ($candidates as $candidate) {
            $path = realpath($candidate);

            // Only real .json files inside this theme's own starter/ folder:
            // a manifest naming ../../.env is refused, not followed.
            if ($path !== false && is_file($path) && str_ends_with($path, '.json')
                && str_starts_with($path, $starter.DIRECTORY_SEPARATOR)) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Import the theme's demo content, adding only what the site lacks.
     *
     * @return array<string, int> totals across every bundle (created per kind, "skipped")
     *
     * @throws RuntimeException with a message fit to show an admin
     */
    public function import(string $themeName, ?string $actorId = null): array
    {
        if ($this->importer === null) {
            throw new RuntimeException('Demo content needs the Magna Pages plugin, which is not enabled on this site.');
        }

        $bundles = $this->bundles($themeName);

        if ($bundles === []) {
            throw new RuntimeException('This theme does not ship demo content.');
        }

        $totals = [];

        foreach ($bundles as $path) {
            $kit = self::read($path);

            try {
                $counts = $this->importer->importMissing($kit, $actorId);
            } catch (\InvalidArgumentException $e) {
                throw new RuntimeException(basename($path).': '.$e->getMessage(), 0, $e);
            }

            foreach ($counts as $kind => $count) {
                $totals[$kind] = ($totals[$kind] ?? 0) + (int) $count;
            }
        }

        Cache::forget(self::onSiteKey($themeName, $bundles));

        return $totals;
    }

    /**
     * One bundle, decoded, held to the size cap.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException with a message fit to show an admin
     */
    public static function read(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BUNDLE_BYTES) {
            throw new RuntimeException(basename($path).' is too large to import.');
        }

        $kit = json_decode((string) file_get_contents($path), true);
        if (! is_array($kit)) {
            throw new RuntimeException(basename($path).' is not valid JSON.');
        }

        /** @var array<string, mixed> $kit */
        return $kit;
    }

    /**
     * One line for an admin: what the import added.
     *
     * @param  array<string, int>  $totals
     */
    public static function summary(array $totals): string
    {
        $labels = ['created' => 'page', 'menus' => 'menu', 'patterns' => 'pattern', 'blog:posts' => 'post'];
        $parts = [];

        foreach ($labels as $key => $noun) {
            $n = (int) ($totals[$key] ?? 0);
            if ($n > 0) {
                $parts[] = $n.' '.$noun.($n === 1 ? '' : 's');
            }
        }

        $skipped = (int) ($totals['skipped'] ?? 0);
        $added = $parts === [] ? 'Nothing new to add' : 'Added '.implode(', ', $parts);

        return $added.($skipped > 0 ? " — {$skipped} already on your site, left unchanged." : '.');
    }

    /**
     * The bundles theme.json declares, as absolute candidate paths — or null
     * when it declares none (every starter bundle then counts).
     *
     * @return list<string>|null
     */
    private function declared(string $root): ?array
    {
        $manifest = json_decode((string) @file_get_contents($root.'/theme.json'), true);
        $list = is_array($manifest) ? ($manifest['demoContent'] ?? null) : null;

        if (! is_array($list)) {
            return null;
        }

        $paths = [];
        foreach ($list as $relative) {
            if (is_string($relative) && $relative !== '') {
                $paths[] = $root.'/'.ltrim($relative, '/');
            }
        }

        return $paths;
    }
}
