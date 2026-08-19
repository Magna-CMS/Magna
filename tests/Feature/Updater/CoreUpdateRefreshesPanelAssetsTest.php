<?php

declare(strict_types=1);

use Magna\Updater\CoreUpdater;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A core update has to bring the panel's compiled assets with the code.
 *
 * It did not, and the failure was invisible until somebody looked at the
 * admin: a release moved the icon font from Google's CDN to a file shipped in
 * `public/fonts`, the Blade partial naming it arrived on update, and the font
 * did not. The request 404'd and — because an icon here is a ligature, a word
 * the font draws as a glyph — every icon in the panel rendered as its own
 * name in plain text: "verified_user" beside the environment heading,
 * "arrow_forward" on the links, "warning" on the warnings.
 *
 * Nothing errored. No log line, no failed update, a panel that simply looked
 * broken, and only on installations that had updated rather than installed
 * fresh, which is the population least likely to be tested. Seen on a live
 * site after it took 1.3.21.
 *
 * The same gap covers every future CSS or JS change: without `public/build`,
 * an updated site keeps the stylesheet it was installed with forever.
 */
it('overlays the panel assets and the fonts they reference', function (): void {
    expect(CoreUpdater::coreOwnedPaths())
        ->toContain('public/build')
        ->toContain('public/fonts');
});

it('never overlays public itself', function (): void {
    // `public` also holds uploads and the storage symlink, and the overlay
    // mirrors with delete: true — listing it would remove a site's media on
    // the next update.
    expect(CoreUpdater::coreOwnedPaths())->not->toContain('public');
});

/**
 * Whatever a release ships under those paths has to actually be in the
 * archive, or the overlay copies nothing and the panel breaks exactly as
 * before. The builder's exclude list is the thing that decides this.
 */
it('does not exclude the panel assets from the release archive', function (): void {
    $builder = file_get_contents(base_path('bin/build-release.php'));

    expect($builder)->toBeString();

    // A crude but load-bearing check: the builder must not name these paths as
    // excluded. If the exclusion syntax changes, this fails and gets read.
    expect($builder)->not->toContain("'public/build'")
        ->and($builder)->not->toContain("'public/fonts'");
});
