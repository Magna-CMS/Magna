<?php

declare(strict_types=1);

namespace Magna\Themes;

use Illuminate\Contracts\Container\Container;
use Magna\Frontend\RenderedOutputRefresher;
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
    public function __construct(
        private readonly Filesystem $files,
        private readonly EnabledPluginVersions $pluginVersions = new EnabledPluginVersions,
        private readonly RenderedOutputRefresher $rendered = new RenderedOutputRefresher,
        // Where a plugin's ThemeContentImporter is found (the page builder
        // binds one). Null when constructed by hand, as some tests do — a
        // manager without it simply reports no demo content.
        private readonly ?Container $container = null,
    ) {}

    /**
     * Demo content for installed themes — what "install with demo data"
     * imports. Reached through the manager so callers that already hold one
     * (the Themes page) need no second container lookup.
     */
    public function content(): ThemeContent
    {
        $importer = $this->container !== null && $this->container->bound(ThemeContentImporter::class)
            ? $this->container->make(ThemeContentImporter::class)
            : null;

        return new ThemeContent($this, $importer instanceof ThemeContentImporter ? $importer : null);
    }

    /**
     * A theme update's content — what lands on the site's pages when a theme
     * is updated (see ThemeContentUpdater).
     */
    public function contentUpdates(): ThemeContentUpdates
    {
        $updater = $this->container !== null && $this->container->bound(ThemeContentUpdater::class)
            ? $this->container->make(ThemeContentUpdater::class)
            : null;

        return new ThemeContentUpdates($this, $updater instanceof ThemeContentUpdater ? $updater : null);
    }

    /** Absolute path of the themes directory. */
    public function directory(): string
    {
        $configured = config('magna.themes_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : base_path('themes');
    }

    /**
     * @throws InvalidThemeException when the name could not have come from a
     *                               valid manifest — this joins it onto the themes root, and remove()
     *                               hands the result to a recursive delete.
     */
    public function pathFor(string $name): string
    {
        // Every caller today passes a manifest-validated name (fromArray
        // enforces the same rule), so this is the second layer of one fence
        // — it keeps the join safe even if a new caller bypasses manifests.
        if (! ThemeManifest::isValidName($name)) {
            throw new InvalidThemeException('"'.$name.'" is not a valid theme name (expected vendor/name, lowercase).');
        }

        [$vendor, $package] = explode('/', $name, 2);

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
        $theme = is_string($name) && $name !== '' ? $this->find($name) : null;

        // An addon can never BE the theme, even if the setting says so.
        return $theme !== null && ! $theme->isAddon() ? $theme : null;
    }

    /**
     * The theme addons that apply right now: installed, compatible, and
     * extending either the active theme by name or any theme ("*").
     * Ordered by precedence (§C8): specific `extends` beats "*", then
     * higher manifest priority, then name — deterministic across requests.
     *
     * @return list<ThemeManifest>
     */
    public function activeAddons(): array
    {
        $activeTheme = $this->active()?->name;

        $addons = [];
        foreach ($this->installed() as $manifest) {
            if (! $manifest->isAddon()
                || ! $manifest->isCompatibleWith(MagnaServiceProvider::VERSION)
            ) {
                continue;
            }
            if ($manifest->extends === '*' || $manifest->extends === $activeTheme) {
                $addons[] = $manifest;
            }
        }

        usort($addons, function (ThemeManifest $a, ThemeManifest $b): int {
            return [$a->extends === '*' ? 1 : 0, -$a->priority, $a->name]
                <=> [$b->extends === '*' ? 1 : 0, -$b->priority, $b->name];
        });

        return $addons;
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

        if ($theme->isAddon()) {
            throw new InvalidThemeException('"'.$theme->displayName.'" is a theme addon — it applies automatically alongside its host theme and cannot be activated as the theme.');
        }

        if (! $theme->isCompatibleWith(MagnaServiceProvider::VERSION)) {
            throw new InvalidThemeException('"'.$theme->displayName.'" is not compatible with this version of Magna.');
        }

        $this->assertPluginFloorsMet($theme);

        $settings = ThemeSettings::get();
        $settings->active = $theme->name;
        $settings->save();

        // A theme's files can reach themes/ by routes core never sees — a copy
        // into the directory, a restored backup — still carrying old mtimes,
        // and activation is the last moment before they render. Every public
        // page changes with the theme, too. See RenderedOutputRefresher.
        $this->rendered->filesReplaced($this->pathFor($theme->name), $theme->name);

        return $theme;
    }

    /**
     * Refuse a theme whose `compat.plugins` floors this site does not meet.
     *
     * Loud rather than silent, which is the whole point. A theme's rendering
     * can depend on a plugin feature — `settings.row.columnTemplate` belongs
     * to magna/pages — and an unknown block setting is DROPPED on the way in,
     * so the same theme on an older plugin draws every two-up band as equal
     * columns with nothing reported anywhere. One theme had 26 such bands.
     * A refusal names the gap; the alternative is a site that looks subtly
     * wrong and no way to find out why.
     *
     * Not checked at install time as well, for the same reason the core check
     * is not: a plugin downgrade or a disable can turn a working theme into
     * an unsupported one with nothing being installed at all.
     *
     * @throws InvalidThemeException
     */
    private function assertPluginFloorsMet(ThemeManifest $theme): void
    {
        if ($theme->pluginCompat === []) {
            return;
        }

        $enabled = $this->pluginVersions->all();

        // Unknown, not empty — the plugins table does not exist yet during
        // installation, and a theme is activated there. See
        // EnabledPluginVersions::all().
        if ($enabled === null) {
            return;
        }

        $unmet = $theme->unmetPluginCompat($enabled);

        if ($unmet === []) {
            return;
        }

        $reasons = [];

        foreach ($unmet as $package => $state) {
            $reasons[] = $state['found'] === null
                ? $package.' '.$state['required'].' (not enabled here)'
                : $package.' '.$state['required'].' (this site has '.$state['found'].')';
        }

        throw new InvalidThemeException(
            '"'.$theme->displayName.'" needs '.implode(', ', $reasons).'. Its layout is drawn against features those versions provide, and a block setting an older plugin does not know is dropped silently.'
        );
    }

    /** Leave the site with no active theme. */
    public function deactivate(): void
    {
        $settings = ThemeSettings::get();
        $previous = $settings->active;
        $settings->active = null;
        $settings->save();

        $this->rendered->outputChanged($previous ?? 'theme');
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
