<?php

declare(strict_types=1);
use Magna\Updater\Engine\EngineContext;
use Magna\Updater\Engine\EngineLoader;

/**
 * The release manifest bin/build-release.php writes into every archive, and
 * the pure functions that assemble it.
 *
 * Why it exists: a one-click update is performed by the release the site is
 * already running, and that code used to decide what to copy from its own
 * constant — so a release could never deliver a path it introduced. 1.4.3
 * added `config/defaults` to the list and no site updating into 1.4.3 got it.
 * The archive now describes itself in magna-release.json, and the installed
 * updater reads that (Magna\Updater\Manifest\ReleaseManifest) instead of
 * remembering. The same rules the updater applies (ManifestRules) run here at
 * build time, so an archive every site would refuse never leaves the build
 * machine.
 *
 * Kept side-effect free (git aside) and separate from the builder so
 * tests/Unit/Release covers it without running a build.
 */

/** The PHP floor every release states; kept equal to the installer's and public/index.php's. */
const RELEASE_PHP_FLOOR = '8.3.0';

/**
 * The oldest release an archive may be applied over directly. The one-click
 * updater first shipped in v1.2.0; a 1.0 site has nothing to run an update
 * with and re-uploads the archive by hand.
 */
const RELEASE_MIN_UPGRADE_FROM = '1.2.0';

/** Git ref of RELEASE_MIN_UPGRADE_FROM, the default base for the removed-classes diff. */
const RELEASE_MIN_UPGRADE_FROM_REF = 'v1.2.0';

/**
 * Extensions the updater and the running site need. The installer's required
 * set, plus zip (the archive) and sodium (the signature on its checksum).
 */
const RELEASE_REQUIRED_EXTENSIONS = ['pdo', 'mbstring', 'openssl', 'ctype', 'fileinfo', 'curl', 'dom', 'tokenizer', 'zip', 'sodium'];

/**
 * Core-owned paths a release archive may legitimately lack: the SDK source is
 * staged only by a hub build.
 */
const RELEASE_OPTIONAL_PATHS = ['bundled/magna-cms/plugin-sdk'];

/** Files whose absence after the overlay means the release did not land. */
const RELEASE_CHECK_FILES = [
    'public/build/manifest.json',
    'src/Magna/Config/defaults/magna.php',
    'bootstrap/magna-autoload.php',
    'vendor/magna-cms/plugin-sdk/composer.json',
];

/** Classes the finalizer proves loadable under the new code. */
const RELEASE_CHECK_CLASSES = [
    'Magna\\Updater\\CoreUpdater',
    'Magna\\Updater\\Manifest\\ReleaseManifest',
    'Magna\\Support\\StaleClassMap',
];

/** Where the release engine lives inside every archive. */
const RELEASE_ENGINE_PATH = 'bootstrap/update/engine.php';

/**
 * What a release asks of the site's vendor/. `replace` by default: the
 * archive ships the complete vendor/ it was built and tested with, and the
 * engine replaces the site's whole when nothing in it is the site's own
 * (runs Composer when something is, refuses when it cannot) — so an updated
 * site runs the same dependencies a fresh install of the release does, and
 * stale classmap entries stop accumulating. `--vendor-strategy=keep` builds
 * an archive that leaves vendor/ alone beyond the SDK, as every release
 * before 1.4.4 did.
 */
const RELEASE_VENDOR_STRATEGY = 'replace';

/**
 * The engine block for a staged archive: which API the engine speaks and the
 * hash the installed updater checks before running it.
 *
 * @return array{api: int, path: string, sha256: string}
 */
function release_engine(string $stage): array
{
    $file = rtrim($stage, '/\\').'/'.RELEASE_ENGINE_PATH;

    if (! is_file($file)) {
        throw new RuntimeException(RELEASE_ENGINE_PATH.' is missing from the staged release; every archive ships its engine.');
    }

    $refusal = EngineLoader::refuse((string) file_get_contents($file));

    if ($refusal !== null) {
        throw new RuntimeException('The staged engine would be refused by every site: '.$refusal);
    }

    return [
        'api' => EngineContext::API,
        'path' => RELEASE_ENGINE_PATH,
        'sha256' => (string) hash_file('sha256', $file),
    ];
}

/**
 * Assemble the manifest for one archive.
 *
 * @param  list<string>  $coreOwnedPaths  what the release's own updater lists — CoreUpdater::coreOwnedPaths()
 * @param  list<string>|null  $removedClasses  null when git could not answer
 * @param  array{commit: string|null, tag: string|null}  $git
 * @param  array{api: int, path: string, sha256: string}|null  $engine  from release_engine(); null only for a manifest-only archive
 * @param  string  $vendorStrategy  one of ManifestRules::VENDOR_STRATEGIES
 * @return array<string, mixed>
 */
function release_manifest(
    string $version,
    array $coreOwnedPaths,
    ?array $removedClasses,
    string $removedSince,
    int $uncompressedBytes,
    array $git,
    string $builtAt,
    ?array $engine = null,
    string $vendorStrategy = RELEASE_VENDOR_STRATEGY,
): array {
    return [
        'schema' => 1,
        'product' => 'magna-cms',
        'version' => $version,
        'built_at' => $builtAt,
        'git' => ['commit' => $git['commit'], 'tag' => $git['tag']],
        'engine' => $engine,
        'requires' => [
            'php' => '>='.RELEASE_PHP_FLOOR,
            'extensions' => RELEASE_REQUIRED_EXTENSIONS,
            'min_upgrade_from' => RELEASE_MIN_UPGRADE_FROM,
        ],
        'paths' => [
            'core_owned' => array_values($coreOwnedPaths),
            'optional' => array_values(array_intersect(RELEASE_OPTIONAL_PATHS, $coreOwnedPaths)),
            'removed' => [],
            // Informational: what the updater's own guard refuses whatever a
            // manifest says. Written so a human reading the archive sees the
            // boundary; the site's PathGuard is what enforces it.
            'protected' => ['.env', 'config/*.php', 'storage', 'public/storage', 'plugins-dev', 'themes', 'vendor', 'composer.json', 'composer.lock'],
        ],
        'vendor' => ['strategy' => $vendorStrategy],
        'removed_classes' => $removedClasses,
        'removed_classes_since' => $removedClasses === null ? null : ltrim($removedSince, 'vV'),
        'checks' => [
            'files' => RELEASE_CHECK_FILES,
            'classes' => RELEASE_CHECK_CLASSES,
        ],
        'size' => ['uncompressed_bytes' => $uncompressedBytes],
    ];
}

/**
 * The fully qualified class a core source file declares, by its PSR-4 path,
 * or null for anything that is not a core class file (a Blade view, a
 * resource, a file outside the two roots).
 */
function release_class_from_path(string $path): ?string
{
    $path = str_replace('\\', '/', $path);

    if (! str_ends_with($path, '.php') || str_ends_with($path, '.blade.php')) {
        return null;
    }

    foreach (['src/Magna/' => 'Magna\\', 'app/' => 'App\\'] as $root => $namespace) {
        if (str_starts_with($path, $root)) {
            $relative = substr($path, strlen($root), -4);

            return $namespace.str_replace('/', '\\', $relative);
        }
    }

    return null;
}

/**
 * Core classes that existed at $sinceRef and do not exist at HEAD — deleted
 * or moved — so the updater can disarm their classmap entries on every site
 * that has upgraded from any version in between.
 *
 * A diff between two endpoints yields the cumulative set: a class removed in
 * 1.3.9 is still in the answer for a site jumping from 1.2.0. Null when git
 * cannot answer (a build from an export rather than a checkout); the runtime
 * scan covers that case, one boot later.
 *
 * @return list<string>|null
 */
function release_removed_classes(string $root, string $sinceRef): ?array
{
    $command = sprintf(
        'git -C %s diff --name-status --diff-filter=DR %s..HEAD -- src/Magna app 2>&1',
        escapeshellarg($root),
        escapeshellarg($sinceRef),
    );

    exec($command, $lines, $code);

    if ($code !== 0) {
        return null;
    }

    return release_classes_from_diff($lines);
}

/**
 * The removed classes in `git diff --name-status` output: `D<TAB>path` for a
 * deletion, `R<score><TAB>old<TAB>new` for a move — the OLD path is the one
 * whose class is gone.
 *
 * @param  list<string>  $lines
 * @return list<string>
 */
function release_classes_from_diff(array $lines): array
{
    $classes = [];

    foreach ($lines as $line) {
        $parts = explode("\t", trim($line));

        if (count($parts) < 2) {
            continue;
        }

        $status = $parts[0];

        if ($status !== 'D' && preg_match('/^R\d*$/', $status) !== 1) {
            continue;
        }

        $class = release_class_from_path($parts[1]);

        if ($class !== null && ! in_array($class, $classes, true)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * Bytes the archive holds once extracted, for the disk-space pre-flight.
 */
function release_tree_bytes(string $stage): int
{
    $bytes = 0;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile()) {
            $bytes += $file->getSize();
        }
    }

    return $bytes;
}

/**
 * The commit and tag the archive was built from, when the build runs in a
 * checkout.
 *
 * @return array{commit: string|null, tag: string|null}
 */
function release_git_identity(string $root): array
{
    return [
        'commit' => release_git_output($root, 'rev-parse --short HEAD'),
        'tag' => release_git_output($root, 'describe --tags --exact-match HEAD'),
    ];
}

/**
 * One line of git output, or null when the command fails (no checkout, no
 * tag on HEAD). Stderr is merged and discarded with the failure rather than
 * redirected to /dev/null, which cmd.exe does not have.
 */
function release_git_output(string $root, string $arguments): ?string
{
    exec('git -C '.escapeshellarg($root).' '.$arguments.' 2>&1', $lines, $code);

    if ($code !== 0 || $lines === []) {
        return null;
    }

    $value = trim((string) $lines[0]);

    return $value === '' ? null : $value;
}
