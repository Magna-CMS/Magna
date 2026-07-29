<?php

declare(strict_types=1);

namespace Magna\Themes;

use Magna\MagnaServiceProvider;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * The installed themes on this site, and which one is active.
 *
 * A theme lives at `themes/<vendor>/<name>/` with a `theme.json` at its root
 * — the same two-level layout as plugins-dev, so a package's identity and its
 * path stay in step and one theme can never quietly overwrite another.
 *
 * There is no boot step and no enable step. A theme is inert on disk: it is
 * data that a front end reads through the Delivery API, so "activating" one
 * is a single setting, and a broken theme can never take the admin down.
 */
class ThemeManager
{
    public function __construct(private readonly Filesystem $files) {}

    /** Absolute path of the themes directory. */
    public function directory(): string
    {
        return base_path('themes');
    }

    public function pathFor(string $name): string
    {
        [$vendor, $package] = array_pad(explode('/', $name, 2), 2, '');

        return $this->directory().'/'.$vendor.'/'.$package;
    }

    /**
     * Every installed theme, keyed by name.
     *
     * A directory whose manifest is missing or malformed is skipped rather
     * than thrown over: one bad theme must not hide the rest of them from
     * the admin who has come to remove it.
     *
     * @return array<string, ThemeManifest>
     */
    public function installed(): array
    {
        $root = $this->directory();

        if (! is_dir($root)) {
            return [];
        }

        $themes = [];

        foreach ((array) glob($root.'/*/*/'.ThemeManifest::FILENAME) as $manifestPath) {
            if (! is_string($manifestPath)) {
                continue;
            }

            try {
                $manifest = ThemeManifest::loadFromFile($manifestPath);
            } catch (Throwable) {
                continue;
            }

            // The manifest's own name decides where it belongs; a package
            // sitting in someone else's directory is not trusted to be it.
            if (realpath(dirname($manifestPath)) !== realpath($this->pathFor($manifest->name))) {
                continue;
            }

            $themes[$manifest->name] = $manifest;
        }

        ksort($themes);

        return $themes;
    }

    public function find(string $name): ?ThemeManifest
    {
        return $this->installed()[$name] ?? null;
    }

    /** The active theme's manifest, or null when none is active or it is gone. */
    public function active(): ?ThemeManifest
    {
        $name = ThemeSettings::get()->active;

        return is_string($name) && $name !== '' ? $this->find($name) : null;
    }

    /**
     * Make a theme the active one.
     *
     * Compatibility is checked here rather than at install time as well,
     * because a core upgrade can turn a working theme into an incompatible
     * one without anything being installed at all.
     *
     * @throws InvalidThemeException
     */
    public function activate(string $name): ThemeManifest
    {
        $theme = $this->find($name);

        if ($theme === null) {
            throw new InvalidThemeException('That theme is not installed.');
        }

        if (! $theme->isCompatibleWith(MagnaServiceProvider::VERSION)) {
            throw new InvalidThemeException('"'.$theme->displayName.'" is not compatible with this version of Magna.');
        }

        $settings = ThemeSettings::get();
        $settings->active = $theme->name;
        $settings->save();

        return $theme;
    }

    /** Leave the site with no active theme. */
    public function deactivate(): void
    {
        $settings = ThemeSettings::get();
        $settings->active = null;
        $settings->save();
    }

    /**
     * Delete an installed theme's files.
     *
     * Removing the active theme deactivates it first, so the site is never
     * left pointing at a directory that no longer exists.
     *
     * @throws InvalidThemeException
     */
    public function remove(string $name): void
    {
        $theme = $this->find($name);

        if ($theme === null) {
            throw new InvalidThemeException('That theme is not installed.');
        }

        if (ThemeSettings::get()->active === $theme->name) {
            $this->deactivate();
        }

        $this->files->remove($this->pathFor($theme->name));
    }
}
