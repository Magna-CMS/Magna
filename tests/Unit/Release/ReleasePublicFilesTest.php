<?php

declare(strict_types=1);

/*
 * public/ was copied verbatim from the build machine's working tree, so a dev
 * checkout's published plugin assets (public/js/magna-cms, public/css/
 * magna-cms) and compiled app front ends (public/erp) rode into the release
 * archive. A release ships what git tracks there, plus the explicitly copied
 * public/build.
 */

require_once dirname(__DIR__, 3).'/bin/support/release-public.php';

it('ships only tracked files under public, never public/build from the list', function (): void {
    $files = release_public_files([
        'public/index.php',
        'public/js/filament/filament/app.js',
        'public\\css\\filament\\filament\\app.css',
        'public/build/manifest.json',
        'app/Http/Kernel.php',
        '',
    ]);

    expect($files)->toBe([
        'public/css/filament/filament/app.css',
        'public/index.php',
        'public/js/filament/filament/app.js',
    ]);
});

it('lists this checkout without untracked plugin output', function (): void {
    $files = release_tracked_public(dirname(__DIR__, 3));

    expect($files)->toContain('public/index.php')
        ->and(array_filter($files, fn (string $f): bool => str_starts_with($f, 'public/js/magna-cms/')))->toBe([])
        ->and(array_filter($files, fn (string $f): bool => str_starts_with($f, 'public/erp/')))->toBe([]);
});

it('no longer copies public/ verbatim', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/build-release.php');

    preg_match('/\$copyDirs = \[(.*?)\];/s', $script, $m);

    expect($m[1] ?? '')->not->toContain("'public'")
        ->and($script)->toContain('release_tracked_public($root)');
});
