<?php

declare(strict_types=1);

namespace Magna\Themes;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Magna\Licensing\PackageExtractor;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

/**
 * "Upload theme": install or update a theme from a zip an admin chose, the
 * way WordPress's Themes → Add New → Upload does.
 *
 * A marketplace download is proven authentic by a signed checksum before it
 * is opened (LicenseInstaller). An upload has no signature — the admin is
 * vouching for it — so it is held to everything else instead:
 *
 *   1. separators: a zip written with backslashes extracts as flat files on
 *      the server, so it is refused with a reason rather than half-installed;
 *   2. structure: PackageExtractor (zip-slip, symlinks, size ceiling);
 *   3. manifest: theme.json at the root, valid name, compatible core;
 *   4. code: the restricted-view audit (ThemeAudit) — errors refuse it, and
 *      a site with nothing to run the audit refuses uploads outright;
 *   5. placement: ThemeInstaller, the same route a marketplace update takes,
 *      so an upload of an installed theme updates it in place AND carries
 *      its content onto the site three-way.
 */
final class ThemeZipInstaller
{
    public function __construct(
        private readonly PackageExtractor $extractor,
        private readonly ThemeInstaller $installer,
        private readonly Filesystem $files,
        private readonly Container $container,
    ) {}

    /**
     * @return string a title, then after "\n" anything the admin should read
     *
     * @throws RuntimeException with a message safe to surface in the admin
     */
    public function install(string $zipPath, ?string $actorId = null): string
    {
        $this->refuseBackslashes($zipPath);

        $lock = Cache::lock('magna.themes.install', 600);

        if (! $lock->get()) {
            throw new RuntimeException('Another theme is being installed right now. Try again in a minute.');
        }

        $tmp = storage_path('app/magna-themes/tmp/'.Str::random(16));

        try {
            $this->extractor->extract($zipPath, $tmp);
            $root = $this->extractor->resolveContentRoot($tmp);

            if (! is_file($root.'/'.ThemeManifest::FILENAME)) {
                throw new RuntimeException('That zip has no theme.json at its root, so it is not a Magna theme.');
            }

            $this->audit($root);

            return $this->installer->place($root, null, $actorId);
        } finally {
            $this->files->remove($tmp);
            $lock->release();
        }
    }

    private function refuseBackslashes(string $zipPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('That file is not a zip archive Magna can open.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_contains((string) $zip->getNameIndex($i), '\\')) {
                    throw new RuntimeException('That zip uses backslash path separators, which do not extract as folders on this server. Build it with forward slashes (PowerShell\'s Compress-Archive writes backslashes).');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function audit(string $root): void
    {
        $audit = $this->container->bound(ThemeAudit::class) ? $this->container->make(ThemeAudit::class) : null;

        if (! $audit instanceof ThemeAudit) {
            throw new RuntimeException('Uploading a theme needs the Magna Pages plugin, which checks a theme\'s code before it is installed.');
        }

        $errors = $audit->errors($root);

        if ($errors !== []) {
            $shown = array_slice($errors, 0, 5);
            $more = count($errors) > 5 ? ' (and '.(count($errors) - 5).' more)' : '';

            throw new RuntimeException('This theme was refused by the code audit: '.implode('; ', $shown).$more.'.');
        }
    }
}
