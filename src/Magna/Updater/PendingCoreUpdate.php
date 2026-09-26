<?php

declare(strict_types=1);

namespace Magna\Updater;

/**
 * The release a queued update is about, recorded at dispatch time so the apply
 * can still be carried out if the queue never runs it (see
 * CoreUpdateProgress::markQueued() and SystemInfoPage::pollCoreUpdate()).
 *
 * Server-side only — it is written to the cache by the dispatching request and
 * read back by a later request on the same install. It is never sent to the
 * browser, for the same reason SystemInfoPage re-reads the release row instead
 * of keeping the URL in component state: this URL is what gets overlaid onto
 * the code that runs on every request.
 *
 * The maintenance secret is minted by CoreUpdateStarter, one per run: it is
 * the key `php artisan down --secret` takes, and the admin who started the
 * update holds the matching bypass cookie so their progress poll keeps
 * answering while everyone else sees the maintenance page.
 *
 * `mode` is `update` (a newer release) or `repair` (the running release,
 * re-applied from its archive so everything it ships is present and recorded).
 */
final readonly class PendingCoreUpdate
{
    public function __construct(
        public string $version,
        public string $zipUrl,
        public ?string $expectedSha256,
        public bool $force = false,
        public ?string $checksumSignature = null,
        public ?string $maintenanceSecret = null,
        public string $mode = 'update',
    ) {}

    public function withMaintenanceSecret(string $secret): self
    {
        return new self(
            version: $this->version,
            zipUrl: $this->zipUrl,
            expectedSha256: $this->expectedSha256,
            force: $this->force,
            checksumSignature: $this->checksumSignature,
            maintenanceSecret: $secret,
            mode: $this->mode,
        );
    }

    public function isRepair(): bool
    {
        return $this->mode === UpdateMode::Repair->value;
    }

    /** @return array{version: string, zip_url: string, sha256: string|null, force: bool, signature: string|null, maintenance_secret: string|null, mode: string} */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'zip_url' => $this->zipUrl,
            'sha256' => $this->expectedSha256,
            'force' => $this->force,
            'signature' => $this->checksumSignature,
            'maintenance_secret' => $this->maintenanceSecret,
            'mode' => $this->mode,
        ];
    }

    /** @param  array<array-key, mixed>  $value  as decoded from the cache, so key types are not guaranteed */
    public static function fromArray(array $value): ?self
    {
        if (! is_string($value['version'] ?? null) || ! is_string($value['zip_url'] ?? null)) {
            return null;
        }

        return new self(
            version: $value['version'],
            zipUrl: $value['zip_url'],
            expectedSha256: is_string($value['sha256'] ?? null) ? $value['sha256'] : null,
            force: (bool) ($value['force'] ?? false),
            checksumSignature: is_string($value['signature'] ?? null) ? $value['signature'] : null,
            maintenanceSecret: is_string($value['maintenance_secret'] ?? null) ? $value['maintenance_secret'] : null,
            mode: ($value['mode'] ?? null) === UpdateMode::Repair->value ? UpdateMode::Repair->value : UpdateMode::Update->value,
        );
    }
}
