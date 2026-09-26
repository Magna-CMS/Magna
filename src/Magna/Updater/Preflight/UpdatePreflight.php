<?php

declare(strict_types=1);

namespace Magna\Updater\Preflight;

use Composer\Semver\Semver;
use Magna\MagnaServiceProvider;
use Magna\Updater\CoreWritability;
use Magna\Updater\UpdateMode;
use Magna\Updater\UpdatePaths;
use Throwable;

/**
 * Everything that must be true before a core update writes a byte.
 *
 * Each check answers with a PreflightProblem rather than throwing, so the
 * orchestrator can refuse with one readable line and the installer or System
 * Info can show the same list ahead of time. Nothing here has side effects.
 *
 * The version guard is the one that matters most. CoreUpdateJob skips a
 * release the install already runs, but the stalled-worker fallback calls
 * apply() directly from the admin's poll and never passed through that guard
 * — so a release the hub re-announced, or an older one it was made to
 * announce, would have been re-applied. A downgrade is a way to reinstate a
 * fixed vulnerability; it is refused on every path in.
 */
final class UpdatePreflight
{
    /**
     * Free space required under storage/ when the archive size is unknown.
     * A release archive is ~25 MB compressed and ~100 MB extracted, plus the
     * snapshot of the core tree; this leaves room for all three at once.
     */
    public const MINIMUM_FREE_BYTES = 150 * 1024 * 1024;

    /** When the size IS known: the archive, its extraction, and the snapshot. */
    public const FREE_SPACE_MULTIPLIER = 3;

    public function __construct(private readonly UpdatePaths $paths) {}

    /**
     * @param  int|null  $archiveBytes  the archive's size when the hub or the manifest reported it
     * @return list<PreflightProblem>
     */
    public function check(string $targetVersion, UpdateMode $mode, ?int $archiveBytes = null): array
    {
        return [
            ...$this->versionProblems($targetVersion, $mode),
            ...$this->diskProblems($archiveBytes),
            ...$this->writabilityProblems(),
        ];
    }

    /**
     * What Update Manager said about the latest release, checked before the
     * download it would otherwise take to find out. Hints only: the archive's
     * manifest is enforced regardless once it is on disk.
     *
     * @return list<PreflightProblem>
     */
    public function hintProblems(?string $requiresPhp, ?string $minUpgradeFrom, ?string $phpVersion = null): array
    {
        $problems = [];
        $php = $phpVersion ?? PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION;

        if ($requiresPhp !== null && ! self::satisfies($php, $requiresPhp)) {
            $problems[] = new PreflightProblem(
                'php_floor',
                "This release needs PHP {$requiresPhp} and this server runs PHP {$php}. Upgrade PHP first.",
            );
        }

        $installed = ltrim(MagnaServiceProvider::VERSION, 'vV');

        if ($minUpgradeFrom !== null && version_compare($installed, $minUpgradeFrom, '<')) {
            $problems[] = new PreflightProblem(
                'min_upgrade_from',
                "This release can be applied over v{$minUpgradeFrom} or newer, and this site runs v{$installed}. Update to an intermediate release first.",
            );
        }

        return $problems;
    }

    /** @param  list<PreflightProblem>  $problems */
    public function firstBlocking(array $problems): ?PreflightProblem
    {
        foreach ($problems as $problem) {
            if ($problem->blocking) {
                return $problem;
            }
        }

        return null;
    }

    /** @return list<PreflightProblem> */
    private function versionProblems(string $targetVersion, UpdateMode $mode): array
    {
        $target = ltrim(trim($targetVersion), 'vV');
        $installed = MagnaServiceProvider::VERSION;

        if (preg_match('/^\d+\.\d+\.\d+/', $target) !== 1) {
            return [new PreflightProblem(
                'malformed_version',
                "\"{$targetVersion}\" is not a version number — refusing to apply it.",
            )];
        }

        if ($mode === UpdateMode::Repair) {
            if (version_compare($target, $installed, '!=')) {
                return [new PreflightProblem(
                    'repair_version',
                    "A repair re-applies the installed release, v{$installed}; v{$target} is a different release. Use an update for that.",
                )];
            }

            return [];
        }

        if (version_compare($target, $installed, '<=')) {
            return [new PreflightProblem(
                'downgrade',
                "v{$target} is not newer than the installed v{$installed} — refusing to apply it. A release is never re-applied or rolled back this way; use a repair for the former.",
            )];
        }

        return [];
    }

    /** @return list<PreflightProblem> */
    private function diskProblems(?int $archiveBytes): array
    {
        $required = $archiveBytes === null
            ? self::MINIMUM_FREE_BYTES
            : max($archiveBytes * self::FREE_SPACE_MULTIPLIER, self::MINIMUM_FREE_BYTES);

        $free = @disk_free_space($this->paths->storagePath);

        // Unknown (a filesystem that will not say) is not a refusal: the
        // download and extraction fail loudly on their own if space runs out,
        // and nothing has been overlaid by then.
        if ($free === false || $free >= $required) {
            return [];
        }

        $freeMb = (int) round($free / 1048576);
        $requiredMb = (int) ceil($required / 1048576);

        return [new PreflightProblem(
            'disk_space',
            "Only {$freeMb} MB is free under storage/ and this update needs about {$requiredMb} MB for the archive, its extraction and the pre-update backup. Free some space and try again.",
        )];
    }

    /** A constraint that will not parse is not held against the release: this is a hint, not the gate. */
    private static function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (Throwable) {
            return true;
        }
    }

    /** @return list<PreflightProblem> */
    private function writabilityProblems(): array
    {
        $writability = new CoreWritability($this->paths->basePath);
        $summary = $writability->summary();

        if ($summary === null) {
            return [];
        }

        return [new PreflightProblem(
            'unwritable',
            $summary.'. A one-click update has to replace those files, so nothing was changed. '.($writability->remedy() ?? ''),
        )];
    }
}
