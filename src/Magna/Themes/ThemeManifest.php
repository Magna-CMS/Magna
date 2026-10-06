<?php

declare(strict_types=1);

namespace Magna\Themes;

use Composer\Semver\Semver;
use Throwable;

/**
 * A theme's `theme.json`, validated.
 *
 * Deliberately NOT Magna\Plugins\Manifest. A plugin manifest requires an
 * `entry` class because a plugin is code that boots; a theme is templates and
 * assets with nothing to boot, and forcing it through the plugin shape would
 * mean either a fake entry class or a plugin manifest whose most important
 * field is optional. Two kinds of package, two manifests.
 *
 * Everything here is presentational except `name` and `compat` — the pair
 * that decides whether this theme may be installed at all.
 */
final class ThemeManifest
{
    /** A full theme (layout shell + tokens + block views). */
    public const TYPE_THEME = 'magna-theme';

    /** An addon: styles a paired plugin's blocks inside a host theme (§5). */
    public const TYPE_ADDON = 'magna-theme-addon';

    /**
     * @param  list<string>  $tags
     * @param  string|null  $extends  Addon only: host theme name, or "*" for theme-agnostic
     * @param  list<string>  $pairsWith  Addon only: plugins whose blocks it may style
     * @param  int  $priority  Addon only: tie-break between addons (higher wins)
     * @param  array<string, array<string, mixed>>  $blockSeeds  What a freshly
     *                                                           inserted block starts with, per block handle, overriding core's own
     *                                                           seed. A theme's blocks are a design vocabulary — dropping `features`
     *                                                           into a timeline band seeded three generic cards, which an author
     *                                                           then deleted before writing the real ones. Merged OVER the registry
     *                                                           seed rather than replacing it, so a theme states only what it wants
     *                                                           to differ and keeps the rest.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $displayName,
        public readonly string $description,
        public readonly string $version,
        public readonly string $author,
        public readonly string $license,
        public readonly string $magnaCompat,
        public readonly ?string $screenshot = null,
        public readonly array $tags = [],
        public readonly ?string $homepage = null,
        public readonly string $type = self::TYPE_THEME,
        public readonly ?string $extends = null,
        public readonly array $pairsWith = [],
        public readonly int $priority = 0,
        public readonly array $blockSeeds = [],
    ) {}

    public function isAddon(): bool
    {
        return $this->type === self::TYPE_ADDON;
    }

    /** The file a theme package is recognised by. */
    public const FILENAME = 'theme.json';

    /**
     * The one rule for a theme name's shape: lowercase vendor/name, exactly
     * one slash, no dots — so a name can never traverse when joined onto the
     * themes root. ThemeManager::pathFor() asserts the same rule before
     * building a path (and remove() hands that path to a recursive delete).
     */
    public static function isValidName(string $name): bool
    {
        return preg_match('#^[a-z0-9]([a-z0-9_-]*[a-z0-9])?/[a-z0-9]([a-z0-9_-]*[a-z0-9])?$#', $name) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $data  decoded theme.json
     *
     * @throws InvalidThemeException
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;

        if (! is_string($name) || ! self::isValidName($name)) {
            throw new InvalidThemeException('A theme needs a "name" in vendor/name form.');
        }

        $version = $data['version'] ?? null;

        if (! is_string($version) || $version === '') {
            throw new InvalidThemeException('Theme "'.$name.'" has no version.');
        }

        $compat = $data['compat'] ?? null;
        $magnaCompat = is_array($compat) && is_string($compat['magna'] ?? null) ? $compat['magna'] : '^1.0';

        $tags = [];
        if (is_array($data['tags'] ?? null)) {
            $tags = array_values(array_filter($data['tags'], 'is_string'));
        }

        $type = ($data['type'] ?? null) === self::TYPE_ADDON ? self::TYPE_ADDON : self::TYPE_THEME;

        $extends = null;
        $pairsWith = [];
        $priority = 0;
        if ($type === self::TYPE_ADDON) {
            $extends = $data['extends'] ?? null;
            if (! is_string($extends) || ($extends !== '*' && preg_match('#^[a-z0-9]([a-z0-9_-]*[a-z0-9])?/[a-z0-9]([a-z0-9_-]*[a-z0-9])?$#', $extends) !== 1)) {
                throw new InvalidThemeException('Addon "'.$name.'" needs "extends": a host theme name or "*".');
            }
            if (is_array($data['pairsWith'] ?? null)) {
                $pairsWith = array_values(array_filter($data['pairsWith'], 'is_string'));
            }
            if ($pairsWith === []) {
                throw new InvalidThemeException('Addon "'.$name.'" needs "pairsWith": the plugins it styles.');
            }
            $priority = is_int($data['priority'] ?? null) ? $data['priority'] : 0;
        }

        return new self(
            name: $name,
            displayName: is_string($data['displayName'] ?? null) && $data['displayName'] !== '' ? $data['displayName'] : $name,
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            version: $version,
            author: is_string($data['author'] ?? null) ? $data['author'] : '',
            license: is_string($data['license'] ?? null) ? $data['license'] : '',
            magnaCompat: $magnaCompat,
            screenshot: is_string($data['screenshot'] ?? null) && $data['screenshot'] !== '' ? $data['screenshot'] : null,
            tags: $tags,
            homepage: is_string($data['homepage'] ?? null) && $data['homepage'] !== '' ? $data['homepage'] : null,
            type: $type,
            extends: $extends,
            pairsWith: $pairsWith,
            priority: $priority,
            blockSeeds: self::blockSeeds($data['blockSeeds'] ?? null),
        );
    }

    /**
     * `blockSeeds` as a map of block handle to a data array.
     *
     * Shaped rather than trusted: a theme.json is a file an author edits, and
     * anything that is not a handle pointing at an array of data is dropped
     * instead of reaching the builder as a seed it cannot insert.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function blockSeeds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $seeds = [];

        foreach ($raw as $handle => $data) {
            if (! is_string($handle) || $handle === '' || ! is_array($data)) {
                continue;
            }

            // A seed is a map of FIELD HANDLE to value. JSON decodes an
            // object that way, but `{"features": ["a", "b"]}` decodes to a
            // list with integer keys — which is a theme author writing a
            // value where a field map belongs. Keep the named entries and
            // drop the rest rather than handing the builder keys no field
            // answers to.
            $fields = [];

            foreach ($data as $field => $value) {
                if (is_string($field) && $field !== '') {
                    $fields[$field] = $value;
                }
            }

            if ($fields !== []) {
                $seeds[$handle] = $fields;
            }
        }

        return $seeds;
    }

    /** @throws InvalidThemeException */
    public static function loadFromFile(string $path): self
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new InvalidThemeException('Could not read '.self::FILENAME.'.');
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new InvalidThemeException(self::FILENAME.' is not valid JSON.');
        }

        return self::fromArray($decoded);
    }

    public function isCompatibleWith(string $coreVersion): bool
    {
        try {
            return Semver::satisfies($coreVersion, $this->magnaCompat);
        } catch (Throwable) {
            return false;
        }
    }
}
