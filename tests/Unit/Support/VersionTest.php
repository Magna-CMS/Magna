<?php

declare(strict_types=1);

use Magna\Support\Version;

/*
 * Mixing "v1.1.0" and "1.1.0" inverted comparisons (version_compare sorts a
 * leading "v" below every digit) and rendered "vv1.1.0". Version is the one
 * normaliser; these pin each spelling.
 */

it('strips a leading v in either case and treats blank as absent', function (): void {
    expect(Version::bare('v1.2.0'))->toBe('1.2.0')
        ->and(Version::bare('V1.2.0'))->toBe('1.2.0')
        ->and(Version::bare(' 1.2.0 '))->toBe('1.2.0')
        ->and(Version::bare('0.4.0-alpha'))->toBe('0.4.0-alpha')
        ->and(Version::bare(''))->toBeNull()
        ->and(Version::bare('v'))->toBeNull()
        ->and(Version::bare(null))->toBeNull();
});

it('labels with exactly one v, whatever spelling arrived', function (): void {
    expect(Version::label('v1.1.0'))->toBe('v1.1.0')
        ->and(Version::label('1.1.0'))->toBe('v1.1.0')
        ->and(Version::label(null))->toBe('');
});

it('compares across spellings', function (string $candidate, string $installed, bool $newer): void {
    expect(Version::isNewer($candidate, $installed))->toBe($newer);
})->with([
    'tagged newer than bare' => ['v1.1.0', '1.0.0', true],
    'bare newer than tagged' => ['1.1.0', 'v1.0.0', true],
    'same release, different spelling' => ['v1.1.0', '1.1.0', false],
    'installed ahead' => ['v1.0.0', '1.1.0', false],
    'prerelease ordering' => ['v0.4.0-alpha', '0.2.1-alpha', true],
    // The exact case the old raw comparison got wrong.
    'ancient installed against a tag' => ['v1.1.0', '0.0.1', true],
]);

it('never calls a missing side newer', function (): void {
    expect(Version::isNewer(null, '1.0.0'))->toBeFalse()
        ->and(Version::isNewer('1.0.0', null))->toBeFalse()
        ->and(Version::isNewer('', '1.0.0'))->toBeFalse();
});
