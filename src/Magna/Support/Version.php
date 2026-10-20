<?php

declare(strict_types=1);

namespace Magna\Support;

/**
 * The one place a version string is normalised, compared or labelled.
 *
 * Versions reach Magna in two spellings: a git tag (`v1.1.0`) from the
 * marketplace, Packagist and GitHub, and a bare number (`1.1.0`) from
 * manifests and MagnaServiceProvider::VERSION. version_compare() sorts a
 * leading "v" below every digit, so mixing the spellings silently inverted
 * comparisons — an installed 1.0.0 against a recorded v1.1.0 read as "not
 * behind", and the hub reported no update for every tagged plugin release.
 * Concatenating "v" onto a tag rendered "vv1.1.0". Every caller now goes
 * through these three methods instead of its own ltrim.
 */
final class Version
{
    /** The bare number — "v1.2.0" and "1.2.0" both become "1.2.0"; blank is null. */
    public static function bare(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $bare = ltrim(trim($version), 'vV');

        return $bare === '' ? null : $bare;
    }

    /** "v1.2.0" for display, whatever spelling arrived; '' when there is none. */
    public static function label(?string $version): string
    {
        $bare = self::bare($version);

        return $bare === null ? '' : 'v'.$bare;
    }

    /**
     * Whether $candidate is a later release than $installed, ignoring the
     * prefix on either side. A missing side is never newer — an absent
     * latest is not an update, and an unknown installed version cannot be
     * proven behind.
     */
    public static function isNewer(?string $candidate, ?string $installed): bool
    {
        $candidate = self::bare($candidate);
        $installed = self::bare($installed);

        if ($candidate === null || $installed === null) {
            return false;
        }

        return version_compare($candidate, $installed, '>');
    }
}
