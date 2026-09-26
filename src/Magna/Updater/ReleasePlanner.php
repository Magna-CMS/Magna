<?php

declare(strict_types=1);

namespace Magna\Updater;

use Magna\MagnaServiceProvider;
use RuntimeException;

/**
 * Decides what one apply will do, from the archive itself.
 *
 * A manifest-carrying release names its own paths and requirements; they are
 * checked here, before the snapshot and before maintenance mode, so a refusal
 * costs nothing but the download. A release without a manifest is applied by
 * the updater's own list, as every update before manifests was. Refusals are
 * thrown, so the orchestrator reports them as "before any files were
 * changed" — which is exactly what they are.
 *
 * Extracted from CoreUpdater (the collaborator pattern) so the decision is
 * testable apart from the download and the overlay.
 */
final class ReleasePlanner
{
    public function __construct(
        private readonly ReleaseArchive $archive,
        private readonly UpdatePaths $paths,
    ) {}

    /**
     * @param  string  $installedVersion  what runs here now; a parameter so a test can plan for any site
     *
     * @throws RuntimeException when the release must not be applied here
     */
    public function plan(string $extractPath, string $targetVersion, string $installedVersion = MagnaServiceProvider::VERSION, UpdateMode $mode = UpdateMode::Update): ReleasePlan
    {
        $manifest = $this->archive->manifest($extractPath);

        if ($manifest === null) {
            return ReleasePlan::legacy();
        }

        $mismatch = $manifest->announcedAs($targetVersion);
        if ($mismatch !== null) {
            throw new RuntimeException($mismatch);
        }

        $unmet = $manifest->unmetRequirements(PHP_VERSION, extension_loaded(...), $installedVersion, $mode);
        if ($unmet !== []) {
            throw new RuntimeException(implode(' ', $unmet));
        }

        $missing = $manifest->missingFrom($extractPath);
        if ($missing !== []) {
            throw new RuntimeException('The archive does not contain '.implode(', ', $missing).', which the release says it needs — refusing to apply a partial release.');
        }

        $plan = ReleasePlan::fromManifest($manifest);

        // The pre-flight checked the updater's own list. A manifest may name
        // more, and those have to be writable too, before anything is copied.
        $beyond = $plan->pathsBeyondLegacy();
        if ($beyond !== []) {
            $writability = new CoreWritability($this->paths->basePath, $beyond);
            $blocker = $writability->summary();

            if ($blocker !== null) {
                throw new RuntimeException($blocker.'. A one-click update has to replace those files. '.($writability->remedy() ?? ''));
            }
        }

        return $plan;
    }

    /** One line for the run log saying what was decided. */
    public function describe(ReleasePlan $plan): string
    {
        if ($plan->manifest === null) {
            return 'The archive carries no release manifest: applying the updater\'s own path list.';
        }

        $line = sprintf(
            'Release manifest read: %d path(s), %d removal(s), %d removed class(es).',
            count($plan->paths),
            count($plan->removedPaths),
            count($plan->manifest->removedClasses),
        );

        if ($plan->manifest->hasEngine()) {
            $line .= ' The release ships an update engine (api '.$plan->manifest->engineApi.'); this updater applies its manifest directly.';
        }

        return $line;
    }
}
