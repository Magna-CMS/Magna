<?php

declare(strict_types=1);

use Magna\Contracts\RegistersMediaSources;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A plugin that stores uploads must say so.
 *
 * The media library is presented to operators as the one screen that accounts
 * for every file the installation holds. Plugins that write uploads to their
 * own disk — empanelment documents, message attachments — broke that quietly:
 * the files existed, the library showed nothing, and nothing anywhere said the
 * list was partial. An inventory that is silently incomplete is worse than one
 * that is missing, because the operator stops looking.
 *
 * Two ways to keep the promise, and a plugin must pick one:
 *
 *   - put the file in the library, through Magna\Media\MediaIngestor; or
 *   - declare it, by implementing Magna\Contracts\RegistersMediaSources.
 *
 * Declaring is not the same as exposing. A confidential source lists names and
 * sizes and sends the operator to the plugin's own screen to open anything —
 * which is how identity documents can be counted centrally without becoming
 * browsable centrally.
 *
 * Structural on purpose: this reads the source of every plugin present, so a
 * plugin added months from now is held to it on the first run, with no
 * dependency on which plugins happen to be enabled in this environment.
 */
it('has every upload-writing plugin either ingest into the library or declare a source', function (): void {
    $offenders = [];

    foreach (pluginDirectories() as $name => $path) {
        $sources = phpSourcesIn($path.'/src');

        if ($sources === []) {
            continue;
        }

        if (! writesUploadsDirectly($sources)) {
            continue;
        }

        if (usesCoreIngestorOnly($sources)) {
            continue;
        }

        if (declaresMediaSources($sources)) {
            continue;
        }

        $offenders[] = $name;
    }

    expect($offenders)->toBe([], implode("\n", [
        'These plugins write uploaded files to storage without accounting for them:',
        '  '.implode(', ', $offenders),
        '',
        'Either ingest through Magna\Media\MediaIngestor so the file lands in the',
        'library, or implement Magna\Contracts\RegistersMediaSources so the library',
        'can list what the plugin holds. Declaring a source does not expose the',
        'files — mark it confidential and they stay unreadable from the library.',
    ]));
});

/**
 * @return array<string, string> plugin name => absolute path
 */
function pluginDirectories(): array
{
    $plugins = [];

    foreach ((array) glob(dirname(__DIR__, 3).'/plugins-dev/*/*', GLOB_ONLYDIR) as $path) {
        $manifest = $path.'/magna.json';

        if (! is_string($path) || ! file_exists($manifest)) {
            continue;
        }

        $plugins[basename(dirname($path)).'/'.basename($path)] = $path;
    }

    return $plugins;
}

/**
 * @return list<string> file contents
 */
function phpSourcesIn(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $sources = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $sources[] = (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

/**
 * Writing an *upload* to storage, rather than any use of the filesystem.
 *
 * Keyed on the UploadedFile-shaped calls — putFile, putFileAs, store, storeAs —
 * so a plugin caching a rendered thumbnail or writing an export is not dragged
 * in. Those are derived artefacts; nobody uploaded them and nobody is looking
 * for them in the media library.
 *
 * @param  list<string>  $sources
 */
function writesUploadsDirectly(array $sources): bool
{
    foreach ($sources as $source) {
        if (preg_match('/->(putFileAs|putFile|storeAs|storePubliclyAs)\s*\(/', $source) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * True when every upload write in the plugin is the core ingestor's own.
 *
 * A plugin that calls MediaIngestor is fine by definition — the ingestor is
 * what puts the row in the media table — but a plugin can do both, and the
 * private-disk half is exactly the half that goes missing. So this only lets a
 * plugin through when it has no direct write of its own at all.
 *
 * @param  list<string>  $sources
 */
function usesCoreIngestorOnly(array $sources): bool
{
    $ingests = false;

    foreach ($sources as $source) {
        if (str_contains($source, 'Magna\\Media\\MediaIngestor')) {
            $ingests = true;
        }
    }

    if (! $ingests) {
        return false;
    }

    // Any direct write alongside the ingestor still has to be declared.
    foreach ($sources as $source) {
        $withoutIngestorCalls = (string) preg_replace('/\$\w*(ingestor|media)\w*->\w+\s*\(/i', '', $source);

        if (preg_match('/->(putFileAs|putFile|storeAs|storePubliclyAs)\s*\(/', $withoutIngestorCalls) === 1) {
            return false;
        }
    }

    return true;
}

/**
 * @param  list<string>  $sources
 */
function declaresMediaSources(array $sources): bool
{
    foreach ($sources as $source) {
        if (str_contains($source, class_basename(RegistersMediaSources::class))) {
            return true;
        }
    }

    return false;
}
