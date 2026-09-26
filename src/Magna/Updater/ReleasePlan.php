<?php

declare(strict_types=1);

namespace Magna\Updater;

use Magna\Updater\Manifest\ReleaseManifest;

/**
 * What one apply will do to the tree, decided before it touches it.
 *
 * Two tiers today. A release that carries a manifest decides its own paths:
 * what to overlay, what may be absent, what to remove, what to check
 * afterwards. A release without one (every archive up to 1.4.4, and an older
 * archive re-applied by hand) gets the updater's own constant list, which is
 * how every update ever worked. Either way the overlay itself reads only this
 * object, so it cannot quietly prefer the constant.
 */
final readonly class ReleasePlan
{
    /**
     * @param  list<string>  $paths  overlaid, in order
     * @param  list<string>  $optionalPaths  may be absent from the archive without refusing the release
     * @param  list<string>  $removedPaths  removed from the live tree after the overlay
     * @param  list<string>  $checkFiles  must exist after the overlay, or it is rolled back
     */
    public function __construct(
        public array $paths,
        public array $optionalPaths,
        public array $removedPaths,
        public array $checkFiles,
        public ?ReleaseManifest $manifest,
    ) {}

    public static function legacy(): self
    {
        return new self(
            paths: CoreUpdater::coreOwnedPaths(),
            optionalPaths: [],
            removedPaths: [],
            checkFiles: [],
            manifest: null,
        );
    }

    public static function fromManifest(ReleaseManifest $manifest): self
    {
        return new self(
            paths: $manifest->coreOwnedPaths,
            optionalPaths: $manifest->optionalPaths,
            removedPaths: $manifest->removedPaths,
            checkFiles: $manifest->checkFiles,
            manifest: $manifest,
        );
    }

    public function tier(): string
    {
        return $this->manifest === null ? 'legacy' : 'manifest';
    }

    /**
     * Everything the snapshot has to hold to undo this plan: the paths the
     * overlay replaces and the ones it removes.
     *
     * @return list<string>
     */
    public function snapshotPaths(): array
    {
        return array_values(array_unique([...$this->paths, ...$this->removedPaths]));
    }

    /**
     * Paths this plan replaces that the updater's own list does not — the
     * ones whose writability nobody has checked yet.
     *
     * @return list<string>
     */
    public function pathsBeyondLegacy(): array
    {
        return array_values(array_diff($this->snapshotPaths(), CoreUpdater::coreOwnedPaths()));
    }
}
