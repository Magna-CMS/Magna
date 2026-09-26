<?php

declare(strict_types=1);

namespace Magna\Updater;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The overlay every update before engines performed, kept for the archives
 * that still need it: lay the release's paths straight over the live tree.
 *
 * Replace each planned path present in the archive (a path the archive lacks
 * is one the plan declared optional, or a legacy path this archive never
 * carried), remove what the release retired, and prove what it promised is
 * there. Never composer.json, composer.lock or vendor/ beyond the SDK, never
 * .env or storage/ — the plan's paths passed PathGuard, and the legacy list
 * never named them.
 *
 * File by file and in place, which is exactly why the engine's staged swap
 * exists; this stays because a manifest-less archive, or one whose engine
 * this updater cannot run, has nothing else.
 */
final class InPlaceOverlay
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly UpdatePaths $paths,
    ) {}

    /**
     * @return list<string> the paths actually laid down
     *
     * @throws RuntimeException when a file the release promised is missing afterwards
     */
    public function apply(string $extractPath, ReleasePlan $plan): array
    {
        $delivered = [];

        foreach ($plan->paths as $relative) {
            $source = $extractPath.'/'.$relative;
            $target = $this->paths->base($relative);

            if (is_dir($source)) {
                $this->files->mirror($source, $target, null, ['override' => true, 'delete' => true]);
                $delivered[] = $relative;
            } elseif (is_file($source)) {
                $this->files->copy($source, $target, true);
                $delivered[] = $relative;
            }
        }

        foreach ($plan->removedPaths as $relative) {
            $this->files->remove($this->paths->base($relative));
        }

        $missing = array_values(array_filter(
            $plan->checkFiles,
            fn (string $relative): bool => ! is_file($this->paths->base($relative)),
        ));

        if ($missing !== []) {
            throw new RuntimeException('After the overlay these files the release expects are missing: '.implode(', ', $missing).'.');
        }

        return $delivered;
    }
}
