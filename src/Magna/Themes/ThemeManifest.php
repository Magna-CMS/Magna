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
    ) {}

    public function isAddon(): bool
    {
        return $this->type === self::TYPE_ADDON;
    }

    /** The file a theme package is recognised by. */
    public const FILENAME = 'theme.json';

    /**
     * @param  array<array-key, mixed>  $data  decoded theme.json
     *
     * @throws InvalidThemeException
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;

        if (! is_string($name) || preg_match('#^[a-z0-9]([a-z0-9_-]*[a-z0-9])?/[a-z0-9]([a-z0-9_-]*[a-z0-9])?$#', $name) !== 1) {
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
        );
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
