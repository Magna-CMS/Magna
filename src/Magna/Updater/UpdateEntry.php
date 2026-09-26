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
        // Hints about the latest release, so the panel can say "needs PHP
        // 8.4" before a download rather than after. The archive's own manifest
        // is what the updater enforces; these only shorten the trip.
        public readonly ?string $requiresPhp = null,
        public readonly ?string $minUpgradeFrom = null,
        // The archive for the version this site ALREADY runs, verified the
        // same way, so a site an older updater left incomplete can repair
        // itself from the panel.
        public readonly ?string $installedDownloadUrl = null,
        public readonly ?string $installedDownloadSha256 = null,
        public readonly ?string $installedDownloadSha256Signature = null,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        // zip_sha256 is the hex-encoded SHA-256 of the archive at zip_url,
        // published by Update Manager alongside the URL. CoreUpdater verifies
        // the downloaded file against this before extracting it — required
        // (not optional) for a core update; see CoreUpdater::apply().
        $checksum = $data['zip_sha256'] ?? null;

        // Ed25519 signature over the canonical {"sha256": "..."} payload (or
        // {"sha256": "...", "version": "..."}), produced by the marketplace's
        // private key. Verified in ReleaseArchive::checkChecksumSignature() —
        // the checksum alone only stops an archive swap, this also stops a
        // forged /updates response.
        $signature = $data['zip_sha256_signature'] ?? null;

        $requires = is_array($data['requires'] ?? null) ? $data['requires'] : [];
        $installed = is_array($data['installed'] ?? null) ? $data['installed'] : [];
        $installedChecksum = $installed['zip_sha256'] ?? null;
        $installedSignature = $installed['zip_sha256_signature'] ?? null;

        return new self(
            latestVersion: is_string($data['latest_version'] ?? null) ? $data['latest_version'] : null,
            updateAvailable: (bool) ($data['update_available'] ?? false),
            changelogUrl: is_string($data['changelog_url'] ?? null) ? $data['changelog_url'] : null,
            downloadUrl: is_string($data['zip_url'] ?? null) ? $data['zip_url'] : null,
            downloadSha256: self::sha256($checksum),
            downloadSha256Signature: is_string($signature) && $signature !== '' ? $signature : null,
            licenseRequired: (bool) ($data['license_required'] ?? false),
            requiresPhp: is_string($requires['php'] ?? null) && trim($requires['php']) !== '' ? trim($requires['php']) : null,
            minUpgradeFrom: is_string($requires['min_upgrade_from'] ?? null) && preg_match('/^v?\d+\.\d+\.\d+/', $requires['min_upgrade_from']) === 1 ? ltrim($requires['min_upgrade_from'], 'vV') : null,
            installedDownloadUrl: is_string($installed['zip_url'] ?? null) ? $installed['zip_url'] : null,
            installedDownloadSha256: self::sha256($installedChecksum),
            installedDownloadSha256Signature: is_string($installedSignature) && $installedSignature !== '' ? $installedSignature : null,
        );
    }

    private static function sha256(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/i', $value) === 1 ? strtolower($value) : null;
    }
}
