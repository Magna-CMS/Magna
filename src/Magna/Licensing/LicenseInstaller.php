<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\Marketplace;
use Magna\Plugins\Manifest;
use Magna\Plugins\PluginDiscovery;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeManifest;
use Magna\Updater\InstalledVersionRecorder;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * Turns a verified download grant into an installed plugin — entirely within
 * core, because a customer's CMS does not have the plugin-manager plugin
 * (that ships only on the operator's own management system).
 *
 * The order of operations here IS the security model, and it is not
 * negotiable:
 *
 *   1. AUTHENTICITY — the grant's sha256 arrives Ed25519-signed; verify that
 *      signature before believing the checksum at all.
 *   2. INTEGRITY — hash the downloaded bytes and compare against that
 *      now-trusted checksum. A MITM'd or substituted archive dies here.
 *   3. STRUCTURE — only then extract, via PackageExtractor (zip-slip,
 *      symlinks, zip-bomb ceiling).
 *
 * Step 3 alone is not sufficient: structure-safe is not the same as
 * authentic. Steps 1 and 2 are why this class exists.
 */
class LicenseInstaller
{
    /** Hard ceiling on a downloaded package, enforced before the checksum runs. */
    private const MAX_DOWNLOAD_BYTES = 250 * 1024 * 1024;

    public function __construct(
        private readonly LicenseClient $client,
        private readonly DownloadGrantClient $grants,
        private readonly LicenseStore $store,
        private readonly PluginManager $plugins,
        private readonly PackageExtractor $extractor,
        private readonly Filesystem $files,
        private readonly ThemeManager $themes,
        private readonly PluginDiscovery $discovery,
        private readonly InstalledVersionRecorder $versions = new InstalledVersionRecorder,
    ) {}

    /**
     * Install (or update) the product behind a wallet licence.
     *
     * @param  array<array-key, mixed>  $grant  the `download` block of a verified response, straight from decoded JSON
     *
     * @throws RuntimeException with a message safe to surface in the admin
     */
    public function installFromGrant(string $productSlug, array $grant): string
    {
        $url = $grant['url'] ?? null;
        $sha256 = $grant['sha256'] ?? null;
        $signature = $grant['sha256_signature'] ?? null;

        if (! is_string($url) || ! is_string($sha256) || ! is_string($signature)) {
            throw new RuntimeException('The download grant from the licence server was incomplete.');
        }

        // 1. Authenticity of the checksum itself.
        if (! SignedPayload::verify($signature, SignedPayload::canonicalize(['sha256' => $sha256]))) {
            throw new RuntimeException('The package checksum failed signature verification — the download was refused.');
        }

        // The signed URL points at the marketplace and nowhere else; a grant
        // that tries to send us elsewhere is a redirect attack, not a bug.
        if (! str_starts_with($url, Marketplace::WEB_BASE.'/')) {
            throw new RuntimeException('The download grant pointed outside the Magna marketplace — the download was refused.');
        }

        $zipPath = $this->download($url);

        try {
            // 2. Integrity of the bytes we actually received.
            if (! hash_equals($sha256, (string) hash_file('sha256', $zipPath))) {
                throw new RuntimeException('The downloaded package did not match its checksum — installation was aborted.');
            }

            // 3. Structure, then place on disk.
            return $this->place($productSlug, $zipPath);
        } finally {
            @unlink($zipPath);
        }
    }

    /**
     * Activate this site on a wallet licence and install what it entitles,
     * in one step (licensing-plan v0.2 W1).
     *
     * The single path from "I own this" to "it is running", shared by the
     * Licences card and by the storefront's post-payment install. A second
     * implementation of this sequence would eventually disagree with the
     * first about when the activation token is cached — and that token is
     * what every later verify and update depends on.
     *
     * The token is stored only after the response's signature has been
     * verified (LicenseClient::install returns null otherwise) and after the
     * bytes are safely on disk: a site that failed to install must not
     * believe it holds an active seat.
     *
     * @throws RuntimeException with a message safe to surface in the admin
     */
    public function installLicense(int $licenseId, string $productSlug): string
    {
        $payload = $this->client->install($licenseId);

        if ($payload === null) {
            throw new RuntimeException('The licence server did not authorise this install. Try again in a moment.');
        }

        $token = $payload['token'] ?? null;
        $grant = $payload['download'] ?? null;

        if (! is_string($token) || ! is_array($grant)) {
            throw new RuntimeException('The licence server returned an unexpected response.');
        }

        $message = $this->installFromGrant($productSlug, $grant);

        $license = is_array($payload['license'] ?? null) ? $payload['license'] : [];

        $this->store->put(new LicenseEntry(
            productSlug: $productSlug,
            token: $token,
            status: is_string($license['status'] ?? null) ? $license['status'] : 'active',
            licenseType: is_string($license['license_type'] ?? null) ? $license['license_type'] : 'unknown',
            expiresAt: is_string($license['license_expires_at'] ?? null) ? Carbon::parse($license['license_expires_at']) : null,
            updateEntitled: true,
            serverTime: is_string($payload['server_time'] ?? null) ? Carbon::parse($payload['server_time']) : Carbon::now(),
            lastVerifiedAt: Carbon::now(),
        ));

        return $message;
    }

    /**
     * Fetch a fresh grant with the site's stored activation token and
     * install — the update path (licensing-plan v0.2 W5).
     *
     * @throws RuntimeException
     */
    public function update(string $productSlug): string
    {
        $entry = $this->store->get($productSlug);

        if ($entry === null) {
            throw new RuntimeException('No licence for '.$productSlug.' is active on this site.');
        }

        $grant = $this->grants->grant($entry->token);

        if ($grant === null) {
            // Carry the marketplace's own words. "Did not authorise a download"
            // was the same sentence for a released seat, a package awaiting
            // review, a lapsed entitlement, an outage and a signature that did
            // not verify — five different fixes behind one message nobody could
            // act on.
            $reason = $this->grants->lastError();

            throw new RuntimeException(
                'The licence server did not authorise a download for '.$productSlug.
                ($reason !== null ? ': '.$reason.'.' : '.')
            );
        }

        return $this->installFromGrant($productSlug, $grant);
    }

    private function download(string $url): string
    {
        $path = storage_path('app/magna-licensing/'.Str::random(16).'.zip');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        try {
            $response = Http::timeout(120)->sink($path)->get($url);
        } catch (Throwable) {
            @unlink($path);

            throw new RuntimeException('The package could not be downloaded from the licence server.');
        }

        // The checksum runs after this, so an endless response body would fill
        // the disk before anything got a chance to reject it. No legitimate
        // plugin package approaches this size.
        if (is_file($path) && filesize($path) > self::MAX_DOWNLOAD_BYTES) {
            @unlink($path);

            throw new RuntimeException('The package exceeded the maximum download size and was discarded.');
        }

        if (! $response->successful()) {
            @unlink($path);

            // 410 is the expected answer for a grant already used or aged
            // out — worth naming, because retrying fixes it.
            throw new RuntimeException($response->status() === 410
                ? 'That download link had already been used. Try installing again.'
                : 'The licence server refused the download.');
        }

        return $path;
    }

    /**
     * Extract, validate the manifest, and move the package into place.
     * An update replaces the existing directory only after the replacement
     * has been extracted and validated, so a corrupt package can never leave
     * a site with a half-removed plugin.
     *
     * Which manifest is present decides what the package IS: `theme.json`
     * means a theme (templates and assets, installed to themes/, nothing to
     * boot), `magna.json` means a plugin. Taking the kind from the archive
     * rather than from the licence means a mislabelled listing cannot get a
     * theme booted as code.
     */
    private function place(string $productSlug, string $zipPath): string
    {
        $tmp = storage_path('app/magna-licensing/tmp/'.Str::random(16));

        try {
            $this->extractor->extract($zipPath, $tmp);
            $contentRoot = $this->extractor->resolveContentRoot($tmp);

            if (is_file($contentRoot.'/'.ThemeManifest::FILENAME)) {
                return $this->placeTheme($productSlug, $contentRoot);
            }

            $manifestPath = $contentRoot.'/magna.json';

            if (! is_file($manifestPath)) {
                throw new RuntimeException('The downloaded package has no magna.json at its root.');
            }

            $manifest = Manifest::loadFromFile($manifestPath);

            // The package must be the product the licence was issued for —
            // a licence for A must never be able to install B.
            if ($manifest->name !== $productSlug) {
                throw new RuntimeException("The downloaded package is \"{$manifest->name}\", not \"{$productSlug}\" — installation was aborted.");
            }

            if (! $manifest->isCompatibleWith(MagnaServiceProvider::VERSION)) {
                throw new RuntimeException("\"{$manifest->name}\" is not compatible with this version of Magna.");
            }

            [$vendor, $package] = explode('/', $manifest->name, 2);
            $targetDir = base_path("plugins-dev/{$vendor}/{$package}");
            $isUpdate = is_dir($targetDir);

            if ($isUpdate) {
                // Keep the old copy until the new one is in place.
                $backup = $targetDir.'.replacing-'.Str::random(8);
                $this->files->rename($targetDir, $backup);

                try {
                    $this->files->rename($contentRoot, $targetDir);
                } catch (Throwable $e) {
                    $this->files->rename($backup, $targetDir);

                    throw new RuntimeException('The update could not be written to disk; the previous version was restored.', 0, $e);
                }

                $this->files->remove($backup);
            } else {
                $this->files->mkdir(dirname($targetDir), 0755);
                $this->files->rename($contentRoot, $targetDir);
            }

            // Recorded from the manifest just validated, rather than left to
            // discovery to find. plugins-dev/ is not a tree discovery reads in
            // production — discoverFromDev() returns nothing there, and even
            // outside production it only reads directories wired in as Composer
            // path repositories. So syncDiscovered() recorded nothing and the
            // enable below answered "Plugin [x] was not found. Run `composer
            // require x` first." for files it had itself written moments before.
            //
            // Disabled on write: enable() below is what turns it on, and it is
            // the one place that runs migrations and registers permissions.
            PluginRecord::query()->updateOrCreate(
                ['name' => $manifest->name],
                [
                    'display_name' => $manifest->displayName,
                    'version' => $manifest->version,
                    'base_path' => $targetDir,
                    'manifest' => $manifest->toArray(),
                    'enabled' => $isUpdate,
                    // These bytes were only downloadable because a licence
                    // entitled this site to them, and that stays true after
                    // the licence entry is forgotten — releasing the seat must
                    // not silently turn a paid plugin into a free one. See
                    // LicenseGate::stateOf().
                    'requires_license' => true,
                ],
            );

            // Discovery memoizes per request, and the page that submitted this
            // install has already run a scan. Bust it so anything reading
            // discovery later in this request sees the new directory too.
            $this->discovery->reset();

            $this->plugins->syncDiscovered();

            // The Plugins page and the dashboard badge read the check-in row,
            // which the install never touched — so a site that had just updated
            // went on being told the same version was available, with an Update
            // button that would fetch a version already on disk. Reads as a
            // failed update, and for a paid plugin it reads as a licence problem.
            $this->versions->record($manifest->name, $manifest->version);

            if (! $isUpdate) {
                // A freshly installed licensed plugin is enabled right away —
                // the admin explicitly asked for it from their own wallet,
                // which is the deliberate action a bundled plugin lacks.
                $this->plugins->enable($manifest->name);
            }

            return $isUpdate
                ? $manifest->name.' updated to '.$manifest->version.'.'
                : $manifest->name.' installed and enabled.';
        } finally {
            $this->files->remove($tmp);
        }
    }

    /**
     * Move a validated theme package into themes/.
     *
     * Nothing is enabled: a theme is inert until an admin activates it, and
     * silently switching a live site's presentation because someone bought a
     * theme would be a surprise nobody asked for. Re-installing the ACTIVE
     * theme is the exception worth noting — the setting holds a name, not a
     * path, so an update keeps it active without anything extra here.
     */
    private function placeTheme(string $productSlug, string $contentRoot): string
    {
        $manifest = ThemeManifest::loadFromFile($contentRoot.'/'.ThemeManifest::FILENAME);

        // Same rule as plugins: a licence for A must never install B.
        if ($manifest->name !== $productSlug) {
            throw new RuntimeException("The downloaded theme is \"{$manifest->name}\", not \"{$productSlug}\" — installation was aborted.");
        }

        if (! $manifest->isCompatibleWith(MagnaServiceProvider::VERSION)) {
            throw new RuntimeException("\"{$manifest->displayName}\" is not compatible with this version of Magna.");
        }

        $targetDir = $this->themes->pathFor($manifest->name);
        $isUpdate = is_dir($targetDir);

        if ($isUpdate) {
            $backup = $targetDir.'.replacing-'.Str::random(8);
            $this->files->rename($targetDir, $backup);

            try {
                $this->files->rename($contentRoot, $targetDir);
            } catch (Throwable $e) {
                $this->files->rename($backup, $targetDir);

                throw new RuntimeException('The update could not be written to disk; the previous version was restored.', 0, $e);
            }

            $this->files->remove($backup);
        } else {
            $this->files->mkdir(dirname($targetDir), 0755);
            $this->files->rename($contentRoot, $targetDir);
        }

        return $isUpdate
            ? $manifest->displayName.' updated to '.$manifest->version.'.'
            : $manifest->displayName.' installed. Activate it from Themes.';
    }
}
