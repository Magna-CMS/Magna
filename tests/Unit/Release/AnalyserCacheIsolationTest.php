<?php

declare(strict_types=1);

/**
 * The analysers keep their caches inside the checkout, not in system temp.
 *
 * Neither tool set a cache location, so both defaulted to one global directory
 * shared by every checkout on the machine — and with several sessions working
 * in worktrees of this repository at once, two concurrent runs tore each
 * other's entries out:
 *
 *   RecursiveDirectoryIterator(...Temp/phpstan/cache/PHPStan/7a/a9):
 *   The system cannot find the file specified
 *
 * That aborts the analysis outright, and it reads like a broken install rather
 * than a collision. A release build died on it.
 *
 * Pinned as *relative*, which is the property that matters: both tools resolve
 * a relative path against their own config file, so every checkout gets its own
 * without any machine path being written down. An absolute path here would be
 * the bug again, spelled differently.
 */
it('gives phpstan a per-checkout cache directory', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 3).'/phpstan.neon.dist');

    expect($config)->toMatch('/^\s*tmpDir:\s*(?!\/)(?![A-Za-z]:)\S+/m');
});

it('gives psalm a per-checkout cache directory', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 3).'/psalm.xml');

    expect($config)->toMatch('/cacheDirectory="(?!\/)(?![A-Za-z]:)[^"]+"/');
});

it('ignores the directory both of them write to', function (): void {
    $ignored = (string) file_get_contents(dirname(__DIR__, 3).'/.gitignore');

    expect($ignored)->toContain('/build/');
});

/**
 * The staging path is the other collision of the same shape: it was keyed on
 * the version alone and the builder starts by deleting it, so a retry begun
 * while the first build was still in its gates staged into a tree the other was
 * removing.
 */
it('keys the release staging directory to the process', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/build-release.php');

    expect($script)->toContain("sys_get_temp_dir().'/magna-release-'.\$version.'-'.getmypid()");
});
