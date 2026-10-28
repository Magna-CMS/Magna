<?php

declare(strict_types=1);

namespace Magna\Themes;

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

    public function __construct(
        private readonly ThemeManager $themes,
        private readonly ?ThemeContentImporter $importer,
    ) {}

    /** Whether this site can import demo content at all (the page builder is installed). */
    public function supported(): bool
    {
        return $this->importer !== null;
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
