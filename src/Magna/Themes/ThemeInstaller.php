<?php

declare(strict_types=1);

namespace Magna\Themes;

use Illuminate\Support\Carbon;
use Magna\Frontend\RenderedOutputRefresher;
use Magna\MagnaServiceProvider;
use Magna\Support\DirectorySwap;
use Magna\Updater\InstalledVersionRecorder;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * Puts an extracted, validated theme package into themes/ — the one route
 * for a theme from the marketplace (LicenseInstaller) and for a zip an admin
 * uploads (ThemeZipInstaller).
 *
 * An update is two things, and both happen here:
 *
 *   1. the files are swapped in place (DirectorySwap), with the previous
 *      version copied to storage first so it can be put back by hand;
 *   2. the theme's site content is carried onto the site three-way
 *      (ThemeContentUpdates): what nobody edited follows the theme, what an
 *      editor changed stays and is listed for review.
 *
 * Until step 2 existed an update moved files only, which is silently inert
 * for a theme whose release is a page or a menu — the site reported itself
 * up to date and looked exactly as before.
 *
 * Nothing is activated: a theme is inert until an admin activates it. An
 * update of the ACTIVE theme keeps it active, because the setting holds a
 * name, not a path.
 */
final class ThemeInstaller
{
    /** Previous versions kept per theme after an update. */
    private const BACKUPS_KEPT = 3;

    public function __construct(
        private readonly ThemeManager $themes,
        private readonly Filesystem $files,
        private readonly InstalledVersionRecorder $versions = new InstalledVersionRecorder,
        private readonly RenderedOutputRefresher $rendered = new RenderedOutputRefresher,
    ) {}

    /**
     * @param  string|null  $expectedName  the product a licence was issued for; a licence for A must never install B
     * @return string a title, then after "\n" anything the admin should read
     *
     * @throws RuntimeException with a message safe to surface in the admin
     */
    public function place(string $contentRoot, ?string $expectedName = null, ?string $actorId = null): string
    {
        $manifest = ThemeManifest::loadFromFile($contentRoot.'/'.ThemeManifest::FILENAME);

        if ($expectedName !== null && $manifest->name !== $expectedName) {
            throw new RuntimeException("The downloaded theme is \"{$manifest->name}\", not \"{$expectedName}\" — installation was aborted.");
        }

        if (! $manifest->isCompatibleWith(MagnaServiceProvider::VERSION)) {
            throw new RuntimeException("\"{$manifest->displayName}\" is not compatible with this version of Magna.");
        }

        // pathFor() asserts the theme-name shape itself before joining it
        // onto the themes root (ThemeManifest::isValidName — the same rule
        // fromArray enforced when this manifest was parsed).
        $targetDir = $this->themes->pathFor($manifest->name);
        $isUpdate = is_dir($targetDir);
        $updates = $this->themes->contentUpdates();

        // The outgoing theme's bundles are the base of the content update,
        // and the swap below is about to replace them.
        $before = $isUpdate ? $updates->snapshot($manifest->name) : [];

        if ($isUpdate) {
            $this->backUp($targetDir, $manifest->name);
            (new DirectorySwap($this->files))->swap($contentRoot, $targetDir);
        } else {
            $this->files->mkdir(dirname($targetDir), 0755);
            $this->files->rename($contentRoot, $targetDir);
        }

        // An updated layout older than its compiled view, and every cached
        // page drawn by the old one, went on serving the old theme — the
        // lekha/billing update on core 1.6.2. See RenderedOutputRefresher.
        $this->rendered->filesReplaced($targetDir, $manifest->name);

        // The Themes page reads the check-in row, which this install never
        // touched, so without this the page kept offering the version just
        // installed.
        $this->versions->record($manifest->name, $manifest->version, 'theme');

        if (! $isUpdate) {
            return $manifest->displayName.' installed. Activate it from Themes.';
        }

        $title = $manifest->displayName.' updated to '.$manifest->version.'.';

        // Only the ACTIVE theme's content is the site's. An installed theme
        // waiting on the shelf would otherwise push its demo pages onto a
        // site that renders with something else.
        if ($this->themes->active()?->name !== $manifest->name) {
            return $title;
        }

        if (! $updates->supported()) {
            // No page builder to apply content with: say what is left undone.
            $notice = ThemeSiteKit::updateNotice($targetDir, $manifest->name);

            return $title.($notice === '' ? '' : "\n".$notice);
        }

        // The files are in either way; a content step that fails is reported
        // as such, never as a failed update.
        try {
            $report = $updates->apply($manifest->name, $before, $actorId);
        } catch (Throwable $e) {
            return $title."\nIts page content was not applied: ".$e->getMessage();
        }

        return $report === null ? $title : $title."\n".ThemeContentUpdates::summary($report);
    }

    /**
     * Copy the version about to be replaced into storage, keeping the last
     * few — the way back if a release turns out wrong.
     */
    private function backUp(string $targetDir, string $name): void
    {
        $root = storage_path('app/magna-themes/backups');
        $prefix = str_replace('/', '__', $name).'_';

        try {
            $this->files->mirror($targetDir, $root.'/'.$prefix.Carbon::now()->format('Ymd-His'));

            $existing = glob($root.'/'.$prefix.'*', GLOB_ONLYDIR) ?: [];
            sort($existing);

            foreach (array_slice($existing, 0, max(0, count($existing) - self::BACKUPS_KEPT)) as $old) {
                $this->files->remove($old);
            }
        } catch (Throwable) {
            // A backup that could not be written never blocks the update:
            // the swap itself restores the old version if it fails.
        }
    }
}
