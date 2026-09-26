<?php

declare(strict_types=1);

namespace Magna\Updater\Manifest;

use Composer\Semver\Semver;
use Magna\Updater\Engine\PathGuard;
use Magna\Updater\UpdateMode;

/**
 * What a release says about itself: magna-release.json at the archive root.
 *
 * This is the fix for the fault that shipped 1.4.3 to sites that could not
 * receive it. The updater used to decide what to copy from a constant on the
 * class already loaded — the PREVIOUS release's idea of the layout — so a
 * release could never deliver a path it introduced. Now the archive says
 * which paths it owns, what it removed, what it requires and what to check
 * afterwards, and the installed updater reads that instead of remembering.
 *
 * Read only from an archive whose checksum has been verified and whose
 * checksum's signature has been checked: the manifest is inside the signed
 * bytes, so it is exactly as trustworthy as the code beside it and not one
 * bit more. Never from the hub's JSON, which is a pointer to the archive,
 * not the archive.
 *
 * Every path here has passed PathGuard. What it says is still bounded by
 * what the site's guard allows — the manifest cannot widen that.
 */
final readonly class ReleaseManifest
{
    public const FILENAME = 'magna-release.json';

    /**
     * @param  list<string>  $requiresExtensions
     * @param  list<string>  $coreOwnedPaths
     * @param  list<string>  $optionalPaths
     * @param  list<string>  $removedPaths
     * @param  list<string>  $removedClasses
     * @param  list<string>  $checkFiles
     * @param  list<string>  $checkClasses
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public int $schema,
        public string $version,
        public ?string $builtAt,
        public ?string $requiresPhp,
        public array $requiresExtensions,
        public ?string $minUpgradeFrom,
        public array $coreOwnedPaths,
        public array $optionalPaths,
        public array $removedPaths,
        public array $removedClasses,
        public array $checkFiles,
        public array $checkClasses,
        public ?int $uncompressedBytes,
        public ?int $engineApi,
        public ?string $enginePath,
        public ?string $engineSha256,
        public string $vendorStrategy,
        public string $sha256,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @param  string  $json  the bytes the manifest was decoded from, so the footprint can name them
     *
     * @throws InvalidReleaseManifestException
     */
    public static function fromArray(array $data, PathGuard $guard, string $json = ''): self
    {
        $problems = ManifestRules::validate($data, $guard);

        if ($problems !== []) {
            throw new InvalidReleaseManifestException($problems);
        }

        $schema = $data['schema'] ?? null;
        $version = $data['version'] ?? null;

        // Already checked by the rules above; restated so the types are proven
        // here rather than assumed from a call the reader cannot see into.
        if (! is_int($schema) || ! is_string($version)) {
            throw new InvalidReleaseManifestException(['The manifest schema or version is malformed.']);
        }

        /** @var array<string, mixed> $requires */
        $requires = is_array($data['requires'] ?? null) ? $data['requires'] : [];
        /** @var array<string, mixed> $paths */
        $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
        /** @var array<string, mixed> $checks */
        $checks = is_array($data['checks'] ?? null) ? $data['checks'] : [];
        /** @var array<string, mixed> $engine */
        $engine = is_array($data['engine'] ?? null) ? $data['engine'] : [];
        /** @var array<string, mixed> $size */
        $size = is_array($data['size'] ?? null) ? $data['size'] : [];
        /** @var array<string, mixed> $vendor */
        $vendor = is_array($data['vendor'] ?? null) ? $data['vendor'] : [];

        return new self(
            schema: $schema,
            version: $version,
            builtAt: is_string($data['built_at'] ?? null) ? $data['built_at'] : null,
            requiresPhp: is_string($requires['php'] ?? null) ? $requires['php'] : null,
            requiresExtensions: self::strings($requires['extensions'] ?? null),
            minUpgradeFrom: is_string($requires['min_upgrade_from'] ?? null) ? ltrim($requires['min_upgrade_from'], 'vV') : null,
            coreOwnedPaths: self::paths($paths['core_owned'] ?? null),
            optionalPaths: self::paths($paths['optional'] ?? null),
            removedPaths: self::paths($paths['removed'] ?? null),
            removedClasses: self::strings($data['removed_classes'] ?? null),
            checkFiles: self::paths($checks['files'] ?? null),
            checkClasses: self::strings($checks['classes'] ?? null),
            uncompressedBytes: is_int($size['uncompressed_bytes'] ?? null) ? $size['uncompressed_bytes'] : null,
            engineApi: is_int($engine['api'] ?? null) ? $engine['api'] : null,
            enginePath: is_string($engine['path'] ?? null) ? PathGuard::normalize($engine['path']) : null,
            engineSha256: is_string($engine['sha256'] ?? null) ? $engine['sha256'] : null,
            vendorStrategy: is_string($vendor['strategy'] ?? null) ? $vendor['strategy'] : 'keep',
            sha256: hash('sha256', $json),
            raw: $data,
        );
    }

    /**
     * The manifest at the root of an extracted archive, or null when the
     * archive predates manifests (every release up to 1.4.4) and the updater
     * must fall back to its own list.
     *
     * @throws InvalidReleaseManifestException when a manifest is present and unusable
     */
    public static function fromExtractedArchive(string $root, PathGuard $guard): ?self
    {
        $path = rtrim($root, '/\\').'/'.self::FILENAME;

        if (! is_file($path)) {
            return null;
        }

        $json = (string) file_get_contents($path);
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new InvalidReleaseManifestException(['The manifest is not valid JSON.']);
        }

        return self::fromArray($data, $guard, $json);
    }

    /**
     * Why this manifest must not be applied here, in words for the admin, or
     * [] when everything it asks for is present.
     *
     * @param  callable(string): bool  $extensionLoaded
     * @return list<string>
     */
    public function unmetRequirements(string $phpVersion, callable $extensionLoaded, string $installedVersion, UpdateMode $mode): array
    {
        $unmet = [];

        if ($this->requiresPhp !== null && ! Semver::satisfies($phpVersion, $this->requiresPhp)) {
            $unmet[] = "This release needs PHP {$this->requiresPhp} and this server runs PHP {$phpVersion}. Upgrade PHP first.";
        }

        $missing = array_values(array_filter($this->requiresExtensions, static fn (string $ext): bool => ! $extensionLoaded($ext)));
        if ($missing !== []) {
            $unmet[] = 'This release needs the PHP extension(s) '.implode(', ', $missing).', which are not enabled on this server.';
        }

        if ($mode === UpdateMode::Update && $this->minUpgradeFrom !== null && version_compare(ltrim($installedVersion, 'vV'), $this->minUpgradeFrom, '<')) {
            $unmet[] = "This release can be applied over v{$this->minUpgradeFrom} or newer, and this site runs v{$installedVersion}. Update to an intermediate release first.";
        }

        return $unmet;
    }

    /** Why the archive is not the release the hub announced, or null when it is. */
    public function announcedAs(string $targetVersion): ?string
    {
        $target = ltrim(trim($targetVersion), 'vV');

        if ($this->version === $target) {
            return null;
        }

        return "The archive says it is v{$this->version} but v{$target} was announced — refusing to apply it. The update server and the release disagree.";
    }

    /** Whether the release wants an engine hand-off this updater does not know how to perform. */
    public function hasEngine(): bool
    {
        return $this->engineApi !== null && $this->enginePath !== null && $this->engineSha256 !== null;
    }

    /**
     * Every path the release wants on disk that the archive does not contain,
     * minus the ones it declared optional.
     *
     * @return list<string>
     */
    public function missingFrom(string $extractedRoot): array
    {
        $missing = [];

        foreach ($this->coreOwnedPaths as $relative) {
            if (in_array($relative, $this->optionalPaths, true)) {
                continue;
            }

            $source = rtrim($extractedRoot, '/\\').'/'.$relative;

            if (! is_dir($source) && ! is_file($source)) {
                $missing[] = $relative;
            }
        }

        return $missing;
    }

    /** @return list<string> */
    private static function strings(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, 'is_string'));
    }

    /** @return list<string> */
    private static function paths(mixed $list): array
    {
        $normalized = [];

        foreach (self::strings($list) as $path) {
            $clean = PathGuard::normalize($path);

            if ($clean !== null && ! in_array($clean, $normalized, true)) {
                $normalized[] = $clean;
            }
        }

        return $normalized;
    }
}
