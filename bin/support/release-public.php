<?php

declare(strict_types=1);

/**
 * Which files under public/ a release ships.
 *
 * public/ used to be copied verbatim from the build machine's working tree,
 * so whatever a dev checkout had written there rode into the archive: plugin
 * assets published by `filament:assets` (public/js/magna-cms, public/css/
 * magna-cms), an app's compiled front end (public/erp). A release must ship
 * what the repository tracks — plus public/build, which is gitignored and
 * copied explicitly after `npm run build`.
 *
 * @param  list<string>  $trackedPaths  output of `git ls-files public`
 * @return list<string> paths relative to the repository root, public/build excluded
 */
function release_public_files(array $trackedPaths): array
{
    $files = [];
    foreach ($trackedPaths as $path) {
        $path = strtr(trim($path), ['\\' => '/']);
        if ($path === '' || ! str_starts_with($path, 'public/') || str_starts_with($path, 'public/build/')) {
            continue;
        }
        $files[] = $path;
    }

    sort($files);

    return array_values(array_unique($files));
}

/**
 * `git ls-files public` for the checkout at $root. Fails the build rather
 * than falling back to a verbatim copy: a release that silently ships
 * untracked files is the defect this exists to stop.
 *
 * @return list<string>
 */
function release_tracked_public(string $root): array
{
    $output = [];
    $code = 0;
    exec('git -C '.escapeshellarg($root).' ls-files -- public 2>&1', $output, $code);

    if ($code !== 0) {
        throw new RuntimeException('git ls-files public failed: '.implode("\n", $output));
    }

    return release_public_files($output);
}
