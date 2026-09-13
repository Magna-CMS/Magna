<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Magna\Licensing\PackageExtractor;
use Magna\Licensing\SignedPayload;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Getting a core release archive onto disk, provably intact: host-allowlisted
 * download, sha256 verification, Ed25519 signature check on the checksum, and
 * hardened extraction. Extracted from CoreUpdater (per the collaborator
 * pattern) so the "is this archive the one the marketplace published?"
 * mechanics are unit-testable apart from the apply orchestration — which
 * keeps the maintenance window, rollback and progress narration to itself.
 *
 * Each step is a separate method rather than one fetch() because the
 * orchestrator narrates progress between them ("Downloading…", "Verifying…",
 * "Extracting…") and the download dominates the wall clock.
 */
class ReleaseArchive
{
    /**
     * A release may only be pulled from hosts the project controls or
     * publishes through. The checksum (not this list) is what proves archive
     * integrity; the list stops an attacker-supplied arbitrary host before a
     * byte is fetched.
     *
     * @var list<string>
     */
    private const ALLOWED_DOWNLOAD_HOSTS = [
        'managemagna.jrstudios.dev',
        'github.com',
        'objects.githubusercontent.com',
        'codeload.github.com',
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly PackageExtractor $extractor,
    ) {}

    /**
     * The checksum defeats an attacker who can swap the archive. It does NOT
     * defeat one who controls the `/updates` response itself, because the URL
     * and the checksum arrive together over the same channel — whoever forges
     * one forges both, and the payload lands on code that runs every request.
     *
     * The Ed25519 signature closes that: it is minted by the marketplace's
     * private key, which never leaves the marketplace, and verified against
     * the public key baked into this build — the same control licensed plugin
     * downloads already use (Magna\Licensing\LicenseInstaller).
     *
     * A present-but-invalid signature is always fatal. A *missing* one is
     * fatal only when `magna.updater.require_signed_checksum` is on, because
     * Update Manager has to publish `zip_sha256_signature` for every release
     * before that can be enforced without bricking updates. Flip the flag as
     * soon as it does — until then this is checksum-only against a hostile
     * update server.
     *
     * @return string|null a refusal message, or null when acceptable
     */
    public function checkChecksumSignature(string $expectedSha256, ?string $signature): ?string
    {
        $required = (bool) config('magna.updater.require_signed_checksum', false);

        if ($signature === null || $signature === '') {
            if ($required) {
                return 'This release was published without a signed checksum — refusing to apply it.';
            }

            Log::warning('Core update applied with an unsigned checksum; the update server has not published zip_sha256_signature.', [
                'sha256' => $expectedSha256,
            ]);

            return null;
        }

        if (! SignedPayload::verify($signature, SignedPayload::canonicalize(['sha256' => $expectedSha256]))) {
            Log::critical('Core update refused: the release checksum failed Ed25519 signature verification.', [
                'sha256' => $expectedSha256,
            ]);

            return 'The release checksum failed signature verification — refusing to apply it. This can mean the update response was tampered with.';
        }

        return null;
    }

    /** Download the archive to a temp path, refusing any untrusted source. */
    public function download(string $zipUrl): string
    {
        $this->guardDownloadUrl($zipUrl);

        $tmpDir = storage_path('app/magna-updates/tmp');
        $this->files->mkdir($tmpDir);
        $zipPath = $tmpDir.'/core-'.uniqid().'.zip';

        $response = Http::timeout(300)->sink($zipPath)->get($zipUrl);
        if (! $response->successful()) {
            throw new RuntimeException("Could not download the release archive (HTTP {$response->status()}).");
        }

        return $zipPath;
    }

    /** @throws RuntimeException if the downloaded archive doesn't match the checksum Update Manager published for it. */
    public function verifyChecksum(string $zipPath, string $expectedSha256): void
    {
        $actual = hash_file('sha256', $zipPath);

        if (! is_string($actual) || ! hash_equals($expectedSha256, $actual)) {
            throw new RuntimeException(
                "Downloaded archive checksum does not match — expected {$expectedSha256}, got ".($actual ?: 'unreadable').
                '. The archive will not be applied.'
            );
        }
    }

    /**
     * Extraction goes through the same PackageExtractor licensed plugin
     * installs use — entry-name validation, symlink rejection, and an
     * uncompressed-size ceiling, all applied before a byte is written.
     *
     * A core archive is a strictly higher-value target than a plugin package
     * (it lands on `bootstrap/` and `src/Magna`), so it must not have weaker
     * structural checks than one.
     */
    public function extract(string $zipPath): string
    {
        $extractPath = storage_path('app/magna-updates/tmp/extract-'.uniqid());

        $this->extractor->extract($zipPath, $extractPath);

        // GitHub-style archives wrap contents in a single top-level folder
        // (e.g. "Magna-<version>/") — descend into it if that's what we got.
        return $this->extractor->resolveContentRoot($extractPath);
    }

    public function cleanup(string $zipPath, string $extractPath): void
    {
        $this->files->remove([$zipPath, $extractPath]);
    }

    private function guardDownloadUrl(string $zipUrl): void
    {
        $scheme = parse_url($zipUrl, PHP_URL_SCHEME);
        $host = parse_url($zipUrl, PHP_URL_HOST);

        if ($scheme !== 'https' || ! is_string($host) || ! in_array(strtolower($host), self::ALLOWED_DOWNLOAD_HOSTS, true)) {
            throw new RuntimeException("Refusing to download a core update from an untrusted source: {$zipUrl}");
        }
    }
}
