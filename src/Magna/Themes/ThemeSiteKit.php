<?php

declare(strict_types=1);

namespace Magna\Themes;

/**
 * The site content a theme ships beside its templates, and what to say about
 * it after an update.
 *
 * A theme update swaps the directory and stops there — correct for a theme
 * whose release is CSS, and silently inert for one whose release is a menu or
 * a page. Those live in `starter/*.json`, a site-kit bundle, and applying one
 * is a separate step: the files really are new, the version really did change,
 * the site reports itself up to date, and the page looks exactly as before.
 * Nothing errors and nothing logs. A theme author never sees it, because they
 * apply the kit by hand while building it.
 *
 * This class is the smallest honest fix: the update says so. It deliberately
 * does NOT apply the kit, for two reasons.
 *
 * The first is architectural: the applier is a command in the page-builder
 * plugin, and core does not branch on which plugin is installed. Applying
 * from here would need a contract the plugin implements — worth doing, and its
 * own task rather than a side effect of this one.
 *
 * The second is that applying a kit is not obviously safe. A sync upserts by
 * slug and replaces a page's whole document, so a kit re-applied over a page
 * an editor has since rewritten would overwrite their work.
 *
 * Both have since been answered. ThemeContentImporter (install with demo
 * data) adds only what a site lacks. ThemeContentUpdater (an update) compares
 * three versions of every entry — the outgoing theme's, the site's, the
 * incoming theme's — and changes only what nobody edited, listing the rest
 * for an admin to decide. The page builder implements both. This notice is
 * what an update says on a site WITHOUT the page builder, where nothing can
 * apply the content.
 */
final class ThemeSiteKit
{
    /** Where a theme keeps its site-kit bundles, relative to its own root. */
    public const DIRECTORY = 'starter';

    /**
     * The site-kit bundles this theme ships, as paths relative to its root.
     *
     * Sorted, because the message quotes them and an admin comparing two
     * updates should not see the same set in a different order.
     *
     * @return list<string>
     */
    public static function bundles(string $themeDir): array
    {
        $found = glob(rtrim($themeDir, '/\\').'/'.self::DIRECTORY.'/*.json');

        if ($found === false) {
            return [];
        }

        $relative = [];

        foreach ($found as $path) {
            if (is_file($path)) {
                $relative[] = self::DIRECTORY.'/'.basename($path);
            }
        }

        sort($relative);

        return $relative;
    }

    /**
     * What to tell an admin after updating this theme — empty when the theme
     * ships no site content and the update is therefore complete on its own.
     *
     * Names the command without naming the plugin's presence: the sync command
     * comes from the page builder, so an install without one has nothing to
     * apply a kit with either, and the sentence stays true in both cases.
     */
    public static function updateNotice(string $themeDir, string $themeName): string
    {
        $bundles = self::bundles($themeDir);

        if ($bundles === []) {
            return '';
        }

        return 'This update also ships site content ('.implode(', ', $bundles).'), which updating files does not apply. '
            .'Apply it with the page builder\'s site-kit sync: php artisan magna:site:sync themes/'.$themeName.'/'.$bundles[0].' — '
            .'review it first on a site whose pages have been edited since, because a kit upserts over the nodes it owns.';
    }
}
