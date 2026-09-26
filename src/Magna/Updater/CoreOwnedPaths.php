<?php

declare(strict_types=1);

namespace Magna\Updater;

/**
 * The paths a one-click update overlays when the archive carries no manifest
 * of its own — every archive up to 1.4.4 — and the list a manifest-carrying
 * release is measured against.
 *
 * This list is frozen in the code performing the update, which is the flaw
 * that shipped 1.4.3 to sites that could not receive it: a release could not
 * deliver a path it introduced, because the previous release's copy of this
 * list did the copying. A release now describes itself (magna-release.json,
 * read by ReleaseManifest) and this list is what applies an archive from
 * before that. Every entry below is here because its absence broke a site.
 */
final class CoreOwnedPaths
{
    /** @var list<string> */
    private const LEGACY = [
        /*
         * Forwarders now: core's real defaults live in src/Magna/Config/
         * defaults, because a release cannot deliver a path it ADDS to
         * this list - the overlay runs under the previous release's code,
         * whose list has never heard of it. 1.4.3 added this entry and
         * every site that updated into 1.4.3 got new code beside no
         * defaults. Kept so the 1.4.3-era forwarders under config/defaults
         * are refreshed on sites whose updater does overlay them, and so a
         * `config/magna.php` written against 1.4.3 keeps resolving.
         *
         * The SUBDIRECTORY, never `config` itself, which holds the files
         * a site edits and must survive an update untouched. Nothing in
         * here is a site's to edit, so mirroring it with `delete` takes
         * nothing from anybody.
         */
        'config/defaults',
        'src/Magna',
        'app',
        'bootstrap',
        'routes',
        'database/migrations',
        // The panel's compiled assets and the fonts they name. Code arrived on
        // an update and these did not, which breaks in the least obvious way
        // available: a Blade partial referencing a font this release added
        // pointed at a file the update never delivered, the request 404'd, and
        // every icon in the admin rendered as its own ligature name —
        // "verified_user", "arrow_forward" — as plain text beside the
        // headings. Nothing errored; the panel simply looked broken, and only
        // on sites that had updated rather than installed fresh.
        //
        // Both directories are core's own build output: hashed asset bundles
        // and font files, no customer content. Deliberately not `public`
        // itself, which also holds uploads and the storage symlink and must
        // survive an update untouched.
        'public/build',
        'public/fonts',
        // The SDK travels with core, because it is core's own contract surface
        // under a vendor path rather than a third-party dependency. Leaving it
        // behind is what made a plugin built against a newer contract
        // uninstallable on an updated site: core arrived, the interface it
        // names did not, and enabling the plugin died with
        // `Interface "Magna\Contracts\…" not found`. Its namespaces are
        // registered at boot by PluginAutoloader, so a contract in a namespace
        // the site's vendor/composer maps predate still resolves.
        CoreUpdater::SDK_PATH,
        // A hub install resolves the SDK through a path repository rooted here,
        // copied (not symlinked) into vendor/ by Composer. Refreshing only the
        // vendor/ copy therefore lasts exactly until the next `composer require`
        // — which every marketplace plugin install runs — and that re-copies the
        // stale source straight back over it, reviving the missing-interface
        // failure the SDK overlay exists to prevent. A core-only release carries
        // no bundled/ directory, and the overlay skips paths the archive does
        // not contain, so listing it here is a no-op outside a hub.
        CoreUpdater::SDK_SOURCE_PATH,
        // `resources/` is deliberately NOT here, and core keeps nothing at
        // runtime inside it. Three render-hook partials were added under
        // resources/views in 1.4.0; the code that renders them shipped with
        // src/Magna and the views did not, so every updated site answered 500
        // on every admin page — "View [filament.magna.footer] not found" —
        // while a fresh install of the same release was perfect. Core's own
        // panel views now live in src/Magna/Admin/Resources/views under the
        // `magna::` namespace, and an architecture test keeps them there.
        // Overlaying resources/ instead would put core's hands on a directory
        // whose remaining contents are build inputs a site may legitimately
        // customise.
    ];

    /** @return list<string> */
    public static function legacy(): array
    {
        return self::LEGACY;
    }
}
