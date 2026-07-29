<?php

declare(strict_types=1);

namespace Magna\Updater;

final class UpdateEntry
{
    public function __construct(
        public readonly ?string $latestVersion,
        public readonly bool $updateAvailable,
        public readonly ?string $changelogUrl,
        public readonly ?string $downloadUrl = null,
        public readonly ?string $downloadSha256 = null,
        public readonly ?string $downloadSha256Signature = null,
        // A newer version exists but this site is not entitled to it. Never
        // true at the same time as $updateAvailable: the marketplace reports
        // one or the other, because offering a download the licence server
        // would refuse is worse than saying nothing.
        public readonly bool $licenseRequired = false,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        // zip_sha256 is the hex-encoded SHA-256 of the archive at zip_url,
        // published by Update Manager alongside the URL. CoreUpdater verifies
        // the downloaded file against this before extracting it — required
        // (not optional) for a core update; see CoreUpdater::apply().
        $checksum = $data['zip_sha256'] ?? null;

        // Ed25519 signature over the canonical {"sha256": "..."} payload,
        // produced by the marketplace's private key. Verified in
        // CoreUpdater::checkChecksumSignature() — the checksum alone only
        // stops an archive swap, this also stops a forged /updates response.
        $signature = $data['zip_sha256_signature'] ?? null;

        return new self(
            latestVersion: is_string($data['latest_version'] ?? null) ? $data['latest_version'] : null,
            updateAvailable: (bool) ($data['update_available'] ?? false),
            changelogUrl: is_string($data['changelog_url'] ?? null) ? $data['changelog_url'] : null,
            downloadUrl: is_string($data['zip_url'] ?? null) ? $data['zip_url'] : null,
            downloadSha256: is_string($checksum) && preg_match('/^[a-f0-9]{64}$/i', $checksum) === 1 ? strtolower($checksum) : null,
            downloadSha256Signature: is_string($signature) && $signature !== '' ? $signature : null,
            licenseRequired: (bool) ($data['license_required'] ?? false),
        );
    }
}
