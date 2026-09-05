<?php

declare(strict_types=1);

/**
 * Magna release builder.
 *
 * Produces a one-click, extract-and-run distribution ZIP:
 *   - Bundles a production (`--no-dev`) vendor/ so the target host needs no Composer.
 *   - Bundles the compiled public/build/ assets.
 *   - Adds a root forwarder (index.php + .htaccess) so the archive can be
 *     extracted to a domain/subdomain root and served without repointing the
 *     web root at public/ — the installer then runs on first visit.
 *   - Excludes all dev-only tooling, tests, docs, plugins, and local state.
 *
 * Usage:
 *   php bin/build-release.php [version] [--hub]
 *
 * Version defaults to MagnaServiceProvider::VERSION with any -dev suffix
 * stripped. The archive is written to downloads/magna-cms-v<version>.zip.
 *
 * `--hub` builds the internal "Magna Hub" profile instead: identical to the
 * core archive except the first-party Magna plugins are resolved from their
 * plugins-dev/ path repositories and bundled into vendor/, so a fresh install
 * ships with them present (still disabled until enabled from the panel or
 * `magna:plugin:install`). Written to downloads/magna-hub-v<version>.zip.
 * Never publish a hub archive — it carries proprietary plugins.
 *
 * Requires PHP 8.3+ with the zip extension and a reachable Composer binary.
 */
const C_RESET = "\033[0m";
const C_GREEN = "\033[32m";
const C_YELLOW = "\033[33m";
const C_RED = "\033[31m";

function say(string $msg, string $color = C_RESET): void
{
    fwrite(STDOUT, $color.$msg.C_RESET.PHP_EOL);
}

function fail(string $msg): never
{
    say('ERROR: '.$msg, C_RED);
    exit(1);
}

$root = dirname(__DIR__);
chdir($root);

// Pure composer.json rules, kept separate so tests cover them without running
// a build — see tests/Unit/Release/ReleaseComposerTest.php.
require_once __DIR__.'/support/release-composer.php';

if (! extension_loaded('zip')) {
    fail('The zip PHP extension is required to build a release.');
}

// ---------------------------------------------------------------------------
// Resolve version.
// ---------------------------------------------------------------------------
// Parse arguments: the first non-flag token is the version. `--hub` selects the
// plugin-bundling profile; every other flag is rejected.
$version = null;
$hub = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--hub') {
        $hub = true;

        continue;
    }
    if (str_starts_with($arg, '--')) {
        fail("Unknown option '{$arg}'. Usage: php bin/build-release.php [version] [--hub]");
    }
    $version ??= $arg;
}

if ($version === null) {
    $providerSource = @file_get_contents($root.'/src/Magna/MagnaServiceProvider.php') ?: '';
    if (preg_match('/const\s+VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $providerSource, $m)) {
        $version = $m[1];
    } else {
        fail('Could not determine version. Pass one explicitly: php bin/build-release.php 1.2.0');
    }
}

// Strip a leading v and any pre-release suffix (e.g. 1.3.0-beta -> 1.3.0).
$version = ltrim($version, 'vV');
$version = preg_replace('/-.*$/', '', $version) ?? $version;

if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fail("Refusing to build: '{$version}' is not a clean semver (x.y.z). Pass one explicitly.");
}

// Version consistency: the archive's version must match the version the code
// actually reports at runtime. MagnaServiceProvider::VERSION drives the
// updater's compatibility checks, plugin `compat.magna` resolution, and the
// admin System Info page — shipping an archive whose filename disagrees with
// that constant would mislabel every one of them. Refuse rather than mislabel.
$providerSource = @file_get_contents($root.'/src/Magna/MagnaServiceProvider.php') ?: '';
if (preg_match('/const\s+VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $providerSource, $vm)) {
    $codeVersion = preg_replace('/-.*$/', '', ltrim($vm[1], 'vV')) ?? $vm[1];
    if ($codeVersion !== $version) {
        fail("Version mismatch: building '{$version}' but MagnaServiceProvider::VERSION is '{$vm[1]}' (normalises to '{$codeVersion}'). Bump the constant first, or pass the matching version.");
    }
}

say($hub
    ? "Building Magna Hub v{$version} (core + bundled plugins)"
    : "Building Magna release v{$version}", C_GREEN);

// ---------------------------------------------------------------------------
// Locate Composer.
// ---------------------------------------------------------------------------
$composer = getenv('COMPOSER_BIN') ?: null;
if ($composer === null) {
    foreach ([
        getenv('HOME').'/.config/herd/bin/composer.phar',
        getenv('USERPROFILE').'/.config/herd/bin/composer.phar',
        'composer.phar',
        'composer',
    ] as $candidate) {
        if (! $candidate) {
            continue;
        }

        if (is_file($candidate)) {
            $composer = $candidate;
            break;
        }

        // Take the path `command -v` resolves to, not the bare candidate. The
        // command below runs `php <composer>`, and php resolves a relative name
        // against the working directory rather than PATH — so accepting a
        // candidate because it is on PATH and then passing the bare name meant
        // "composer.phar" was chosen on a machine where PATH had it and the
        // install died with "Could not open input file: composer.phar". Windows
        // never hit it because it matches the is_file branch above first.
        $resolved = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));

        if ($resolved !== '' && str_starts_with($resolved, '/')) {
            $composer = $resolved;
            break;
        }
    }
}
$composer ??= 'composer';

$phpBin = PHP_BINARY;

// ---------------------------------------------------------------------------
// Staging directory.
// ---------------------------------------------------------------------------
$stage = sys_get_temp_dir().'/magna-release-'.$version;
say("Staging in {$stage}");
rrmdir($stage);
mkdir($stage, 0755, true);

// Directories copied verbatim (runtime code + assets).
$copyDirs = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'schemas', 'src'];
// Individual files needed at runtime / for the install.
$copyFiles = ['artisan', 'composer.json', 'composer.lock', '.env.example'];

// Paths (relative to their parent) that must never enter the release.
$excludeNames = [
    '.git', '.github', 'node_modules', 'vendor', 'tests', 'benchmarks',
    'plugins-dev', 'docs', 'screenshots', 'downloads',
    '.env', 'database.sqlite', 'storage', // storage skeleton is regenerated below
];
// File extensions dropped anywhere in the tree.
$excludeExt = ['sqlite', 'sqlite-journal', 'log', 'key'];

foreach ($copyDirs as $dir) {
    $src = $root.'/'.$dir;
    if (! is_dir($src)) {
        say("  skip (missing): {$dir}", C_YELLOW);

        continue;
    }
    say("  copy {$dir}/");
    copy_tree($src, $stage.'/'.$dir, $excludeNames, $excludeExt);
}

foreach ($copyFiles as $file) {
    if (is_file($root.'/'.$file)) {
        copy($root.'/'.$file, $stage.'/'.$file);
        say("  copy {$file}");
    }
}

// ---------------------------------------------------------------------------
// Rewrite path-repository URLs for @dev first-party packages so Composer can
// resolve and *copy* (not symlink) them into the release vendor/ tree. In dev
// these are relative links to sibling working copies; a release must carry a
// standalone copy of each.
// ---------------------------------------------------------------------------
$composerJson = json_decode((string) file_get_contents($stage.'/composer.json'), true);
if (! is_array($composerJson)) {
    rrmdir($stage);
    fail('Could not parse staged composer.json.');
}

// Strip bundled plugins from the release. The public core template ships with
// NO plugins — they are distributed separately (own repos / marketplace / local
// ZIP upload). magna-cms/plugin-sdk is deliberately kept: it is the SDK library
// the core plugin system depends on, not a plugin.
// Discovered from the working copy's own path repositories, not hardcoded.
// A hardcoded list silently ships whatever it has not been told about: a
// client plugin wired in after the list was written is not in $stripPlugins,
// so nothing strips it and the hub bundles another customer's source.
//
// What counts is whether the package *is* a plugin — see path_repo_is_plugin()
// — not where its URL happens to point. The test used to read "the URL contains
// plugins-dev/", which a plugin wired in by absolute path, or reached through a
// symlink out of plugins-dev/, walks straight past: it is not recognised as a
// plugin, so it is never stripped, and the release ships a client's source in
// vendor/. That is the exact failure the paragraph above was written to prevent.
$allPlugins = [];
foreach (($composerJson['repositories'] ?? []) as $repo) {
    if (($repo['type'] ?? null) !== 'path' || ! isset($repo['url'])) {
        continue;
    }

    $repoUrl = str_replace('\\', '/', (string) $repo['url']);

    if (! path_repo_is_plugin($root, $repoUrl)) {
        continue;
    }

    $discovered = path_repo_package_name($root, $repoUrl);

    if ($discovered !== null) {
        $allPlugins[] = $discovered;
    }
}

$allPlugins = array_values(array_unique($allPlugins));

// The hub ships the Core Plugin Manager and nothing else: every other plugin
// is uploaded through it. Bundling more was a false economy — the extra
// plugins arrived disabled anyway, and a bundled plugin is just a plugin whose
// first update has to be done by hand.
//
// This is an allow-list on purpose. $allPlugins above is discovered from the
// working copy's own path repositories, so a plugin wired in later is stripped
// by default rather than shipped by omission.
$bundledPlugins = $hub ? ['magna/plugin-manager'] : [];
$stripPlugins = array_values(array_diff($allPlugins, $bundledPlugins));

foreach ($stripPlugins as $pkg) {
    if (isset($composerJson['require'][$pkg]) || isset($composerJson['require-dev'][$pkg])) {
        say("  stripped plugin from release: {$pkg}");
    }
}
foreach ($bundledPlugins as $pkg) {
    if (isset($composerJson['require'][$pkg]) || isset($composerJson['require-dev'][$pkg])) {
        say("  bundling plugin: {$pkg}");
    }
}

// Bundled plugins are wired as require-dev in a working copy (they are dev
// tooling for core development). The release installs with --no-dev, so a
// require-dev entry would be silently dropped from vendor/ — promote them.
$composerJson = release_apply_plugin_profile($composerJson, $stripPlugins, $bundledPlugins);

// A local working copy wires those plugins in through `type: path` repositories
// pointing at plugins-dev/. Drop the repositories whose package was stripped
// above — they have nothing left to resolve, and the rewrite below would
// otherwise demand sources the release does not need. Repositories for bundled
// packages stay so Composer can copy them into the release vendor/ tree.
$composerJson = release_filter_path_repositories(
    $composerJson,
    $stripPlugins,
    static fn (string $url): ?string => path_repo_package_name($root, $url),
);

// A plain release ships no path sources at all: everything it still requires
// is public, so the local wiring is dropped and the branch constraints that
// went with it are swapped for the stable ranges those packages publish under.
// Absolute build-machine URLs used to be written here instead, which broke
// every Composer command on the target — see release_publicise_path_repositories().
if (! $hub) {
    $composerJson = release_publicise_path_repositories(
        $composerJson,
        static fn (string $url): ?string => path_repo_package_name($root, $url),
        static fn (string $url): ?string => path_repo_public_constraint($root, $url),
    );
}

// A hub keeps its path repositories, so each source travels inside the archive
// and the URL stays relative to the deployment root. PLUGIN_SDK_PATH can
// override the sibling SDK location; everything else resolves relative to $root.
$sdkOverride = getenv('PLUGIN_SDK_PATH') ?: null;
foreach ($hub ? ($composerJson['repositories'] ?? []) : [] as $i => $repo) {
    if (($repo['type'] ?? null) !== 'path' || ! isset($repo['url'])) {
        continue;
    }
    $url = $repo['url'];
    if ($sdkOverride && str_contains($url, 'magna-plugin-sdk')) {
        $abs = $sdkOverride;
    } else {
        $abs = str_starts_with($url, '.') ? $root.'/'.$url : $url;
    }
    $abs = realpath($abs) ?: $abs;
    if (! is_dir($abs)) {
        rrmdir($stage);
        fail("Path repository source not found: {$url} (resolved {$abs}). Check plugins-dev/ / PLUGIN_SDK_PATH.");
    }

    $relative = release_bundle_relative_path(path_repo_package_name($root, $abs), $abs);

    // Its own exclusion list, not the archive-wide one: that list drops
    // anything named "docs" or "plugins-dev" (meaningful at the app root,
    // wrong inside a package) and keeps "dist"/"bin" (a plugin's own
    // release zips and build tooling, which must not ship).
    $bundleExcludes = [
        '.git', '.github', '.gitignore', '.gitattributes', 'node_modules',
        'vendor', 'tests', 'dist', 'bin', 'storage', '.env',
        'phpunit.xml', 'phpunit.xml.dist', '.phpunit.result.cache',
    ];
    copy_tree($abs, $stage.'/'.$relative, $bundleExcludes, []);
    $composerJson['repositories'][$i]['url'] = $relative;
    $composerJson['repositories'][$i]['options']['symlink'] = false;
    say("  bundled path repo source -> {$relative}");
}

$composerJson = release_drop_require_dev($composerJson);

file_put_contents(
    $stage.'/composer.json',
    json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
// composer.json now differs from the lock (rewritten path-repo URLs), so the
// lock would be rejected as out of date. Drop it and let Composer resolve
// against the pinned constraints + copied path sources. The install below
// writes a fresh lock that matches whatever shipped — which for a hub means
// relative, in-archive path repositories, so `composer require` still
// resolves on the target instead of pruning the bundled packages.
@unlink($stage.'/composer.lock');

// ---------------------------------------------------------------------------
// Compiled assets: public/build/ is gitignored, so copy it from the working
// tree. Fail loudly if it is missing — a release without assets is broken.
// ---------------------------------------------------------------------------
if (! is_file($root.'/public/build/manifest.json')) {
    rrmdir($stage);
    fail('public/build/manifest.json missing. Run `npm run build` before building a release.');
}
copy_tree($root.'/public/build', $stage.'/public/build', [], []);
say('  copy public/build/ (compiled assets)');

// A live public/storage symlink cannot be zipped portably; the installer /
// `php artisan storage:link` recreates it on the target.
@unlink($stage.'/public/storage');

// ---------------------------------------------------------------------------
// Fresh, writable storage + bootstrap/cache skeleton (no local state).
// ---------------------------------------------------------------------------
$skeleton = [
    'storage/app/public',
    'storage/app/private',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/testing',
    'storage/framework/views',
    'storage/logs',
    'bootstrap/cache',
];
foreach ($skeleton as $dir) {
    @mkdir($stage.'/'.$dir, 0755, true);
    file_put_contents($stage.'/'.$dir.'/.gitkeep', '');
}
// Drop any stale compiled bootstrap cache that rode along in the copy.
foreach (glob($stage.'/bootstrap/cache/*.php') ?: [] as $stale) {
    @unlink($stale);
}

// ---------------------------------------------------------------------------
// Production dependencies.
// ---------------------------------------------------------------------------
say('Installing production dependencies (--no-dev)...', C_GREEN);
// --no-scripts: skip post-autoload-dump (package:discover), which boots Laravel
// and would need a database that does not exist at build time. The package
// manifest and config caches regenerate on the target's first request.
//
// --classmap-authoritative is deliberately absent from BOTH profiles. An
// authoritative classmap disables PSR-4 fallback entirely, so a class that
// arrives after the build is simply "not found" until Composer regenerates
// the map — on hosts that may have no Composer binary at all. That is not a
// hub-only scenario: EVERY customer site installs marketplace plugins as
// dropped files (the licensed-download path extracts a zip, and
// PluginAutoloader registers its PSR-4 at runtime). The core profile carried
// the flag on the assumption that only CoreUpdater ever changes files under
// a release — and every roya/erp install then died with "Plugin entry class
// [Roya\Erp\RoyaErpPlugin] does not exist" the moment it was enabled.
// --optimize-autoloader stays: it builds the classmap for everything known
// at build time without forbidding runtime additions.
$autoloadFlags = '--optimize-autoloader';
$cmd = sprintf(
    '%s %s install --no-dev %s --no-scripts --no-interaction --no-progress --working-dir=%s 2>&1',
    escapeshellarg($phpBin),
    escapeshellarg($composer),
    $autoloadFlags,
    escapeshellarg($stage)
);
passthru($cmd, $code);
if ($code !== 0) {
    rrmdir($stage);
    fail('composer install failed. See output above.');
}

// Dependencies ship their own documentation — readmes, changelogs, upgrade
// guides, contributor notes. None of it is read at runtime, and it is all a
// click away on Packagist, so keep the archive to code.
$docs = 0;
$tree = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage.'/vendor', FilesystemIterator::SKIP_DOTS)
);
foreach ($tree as $file) {
    /** @var SplFileInfo $file */
    if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
        @unlink($file->getPathname());
        $docs++;
    }
}
if ($docs > 0) {
    say("  removed {$docs} vendor documentation files");
}

// ---------------------------------------------------------------------------
// Root forwarder — the piece that makes "extract to the domain root" work.
// ---------------------------------------------------------------------------
say('Writing root forwarder (index.php + .htaccess)', C_GREEN);
file_put_contents($stage.'/index.php', root_index_php());
file_put_contents($stage.'/.htaccess', root_htaccess());
file_put_contents($stage.'/web.config', root_web_config());

// A short readme so a human opening the archive knows what to do.
file_put_contents($stage.'/INSTALL.txt', install_readme($version));

// ---------------------------------------------------------------------------
// Zip it.
// ---------------------------------------------------------------------------
@mkdir($root.'/downloads', 0755, true);
$archiveName = ($hub ? 'magna-hub-v' : 'magna-cms-v').$version.'.zip';
$zipPath = $root.'/downloads/'.$archiveName;
@unlink($zipPath);

say("Creating {$zipPath}", C_GREEN);
$zip = new ZipArchive;
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    rrmdir($stage);
    fail("Could not open {$zipPath} for writing.");
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
// Unix permission bits stored in the archive. PHP's ZipArchive writes 0 by
// default; some hosts then extract every file as 0000 (unreadable) and the app
// dies with "Permission denied" reading vendor/. Stamp sane POSIX modes so an
// extract is immediately servable: 0755 dirs, 0644 files.
$fileMode = (0100000 | 0644) << 16; // regular file, rw-r--r--
$dirMode = (0040000 | 0755) << 16; // directory, rwxr-xr-x

$count = 0;
foreach ($files as $file) {
    /** @var SplFileInfo $file */
    $local = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($stage))), '/');
    if ($file->isDir()) {
        $zip->addEmptyDir($local);
        $zip->setExternalAttributesName($local.'/', ZipArchive::OPSYS_UNIX, $dirMode);
    } else {
        $zip->addFile($file->getPathname(), $local);
        $zip->setExternalAttributesName($local, ZipArchive::OPSYS_UNIX, $fileMode);
        $count++;
    }
}
$zip->close();

rrmdir($stage);

// ---------------------------------------------------------------------------
// Self-verification. Every past release break has been one of two things: a
// file the app needs was missing from the archive, or entries carried no unix
// permission bits and a host extracted them unreadable. Fail the build loudly
// here rather than shipping a broken zip.
// ---------------------------------------------------------------------------
verify_release($zipPath);

// Publish a SHA-256 sidecar (sha256sum format: "<hash>  <filename>"). This is
// the value the update feed's `zip_sha256` must carry — CoreUpdater refuses to
// apply an update whose downloaded archive doesn't hash to it — and it lets a
// human verify integrity with `sha256sum -c magna-cms-v<version>.zip.sha256`.
$sha = hash_file('sha256', $zipPath);
if ($sha === false) {
    fail('Could not compute the SHA-256 checksum for the archive.');
}
file_put_contents($zipPath.'.sha256', $sha.'  '.basename($zipPath)."\n");
say('  SHA-256: '.$sha, C_GREEN);

$sizeMb = round(filesize($zipPath) / 1048576, 1);
say("Done. {$count} files, {$sizeMb} MB -> downloads/{$archiveName}", C_GREEN);

// ===========================================================================
// Helpers.
// ===========================================================================

/**
 * Open the finished archive and assert it is actually installable: the files
 * the app cannot boot without are present, and every entry carries readable
 * POSIX permissions (never mode 0, which some hosts extract as unreadable).
 */
function verify_release(string $zipPath): void
{
    say('Verifying archive', C_GREEN);

    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        fail("Verification could not reopen {$zipPath}.");
    }

    // Files the application cannot run without.
    $required = [
        'index.php', '.htaccess', 'artisan', '.env.example',
        'public/index.php', 'public/.htaccess', 'public/build/manifest.json',
        'vendor/autoload.php', 'vendor/composer/autoload_real.php',
        'vendor/symfony/deprecation-contracts/function.php',
    ];

    $missing = [];
    foreach ($required as $name) {
        if ($zip->locateName($name) === false) {
            $missing[] = $name;
        }
    }
    if ($missing !== []) {
        $zip->close();
        fail('Archive is missing required files: '.implode(', ', $missing));
    }

    // The shipped composer.json must be resolvable on the target. v1.3.19 and
    // v1.3.20 both went out carrying a path repository pointing at the build
    // machine's own SDK checkout, so every Composer command on a released site
    // failed with "The `url` supplied for the path (…) repository does not
    // exist" — including the `composer require` behind every plugin install.
    $shippedComposer = json_decode((string) $zip->getFromName('composer.json'), true);
    foreach (is_array($shippedComposer) ? ($shippedComposer['repositories'] ?? []) : [] as $repo) {
        if (! is_array($repo) || ($repo['type'] ?? null) !== 'path') {
            continue;
        }

        $url = str_replace('\\', '/', (string) ($repo['url'] ?? ''));
        $isAbsolute = str_starts_with($url, '/') || preg_match('#^[A-Za-z]:/#', $url) === 1;

        if ($isAbsolute || $zip->locateName(rtrim($url, '/').'/composer.json') === false) {
            $zip->close();
            fail("Archive composer.json has an unresolvable path repository: {$url}. Its source is not inside the archive.");
        }
    }

    /*
     * And no dev constraint. The release checklist has always said an archive
     * must carry neither a path repository nor an `@dev` / `dev-*` constraint,
     * and this function only ever checked the first of those.
     *
     * A working copy pins the SDK at `@dev` against a sibling checkout, and the
     * builder rewrites that to the SDK's published constraint by reading the
     * sibling's composer.json. Build from somewhere that sibling is not beside
     * — a git worktree, say — and the read fails, the rewrite is skipped, and
     * `@dev` ships. The archive still installs, because the lock pins a real
     * version; the damage arrives later, when a plugin install runs `composer
     * require` on the customer's server and Composer is free to resolve the SDK
     * to an unreleased dev branch. v1.3.25 was one upload away from going out
     * like that.
     */
    $devPins = release_dev_constraints(is_array($shippedComposer) ? $shippedComposer : []);

    if ($devPins !== []) {
        $zip->close();
        $named = [];
        foreach ($devPins as $package => $constraint) {
            $named[] = "{$package} ({$constraint})";
        }
        fail('Archive composer.json pins '.implode(', ', $named).' to a development constraint. '
            .'A released site would be free to resolve them to an unreleased branch.');
    }

    // Permission bits: scan every entry, flag any regular file whose stored
    // unix mode is 0 (which becomes 0000/unreadable on strict extractors).
    $badPerms = 0;
    $firstBad = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $opsys = 0;
        $attr = 0;
        $zip->getExternalAttributesIndex($i, $opsys, $attr);
        $mode = ($attr >> 16) & 0xFFFF;
        if (($mode & 0777) === 0) {
            $badPerms++;
            $firstBad ??= $name;
        }
    }
    $zip->close();

    if ($badPerms > 0) {
        fail("{$badPerms} archive entries have no permission bits (e.g. {$firstBad}). Hosts may extract them unreadable.");
    }

    say('  OK — required files present, permissions set on all entries', C_GREEN);
}

function copy_tree(string $src, string $dst, array $excludeNames, array $excludeExt): void
{
    @mkdir($dst, 0755, true);
    $items = scandir($src) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (in_array($item, $excludeNames, true)) {
            continue;
        }
        $from = $src.'/'.$item;
        $to = $dst.'/'.$item;
        if (is_dir($from)) {
            copy_tree($from, $to, $excludeNames, $excludeExt);
        } else {
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, $excludeExt, true)) {
                continue;
            }
            copy($from, $to);
        }
    }
}

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function root_index_php(): string
{
    return <<<'PHP'
<?php

/**
 * Magna root forwarder.
 *
 * This file lets you extract Magna to the root of a domain or subdomain and
 * have it just work — no need to repoint your web server's document root at
 * the public/ folder. Every request that reaches here is handed to Laravel's
 * real front controller in public/index.php.
 *
 * For the most secure setup on a server you control, point the document root
 * at the public/ directory directly and this file is simply never used.
 */

require __DIR__.'/public/index.php';
PHP;
}

function root_htaccess(): string
{
    return <<<'HT'
# Magna root forwarder (Apache / LiteSpeed).
#
# Serves the whole application from public/ while keeping the archive extracted
# at the domain root. Requests for application internals (.env, source, vendor,
# storage, …) never resolve to a real file and are denied outright.

Options -Indexes

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Block direct access to sensitive top-level paths.
    RewriteRule ^(\.env|\.git|composer\.(json|lock)|artisan) - [F,L]
    RewriteRule ^(app|bootstrap|config|database|lang|resources|routes|schemas|src|storage|tests|vendor)(/|$) - [F,L]

    # Strip trailing slashes here, while REQUEST_URI is still the client's.
    # Laravel's own trailing-slash redirect in public/.htaccess captures
    # %{REQUEST_URI} — which, after the forwarder below has run, is the
    # internally rewritten /public/... path. It then answered /erp/ with a
    # 301 to /public/erp, leaking the internal layout as the browser URL.
    RewriteCond %{REQUEST_URI} !^/public/
    RewriteRule ^(.+)/$ /$1 [R=301,L]

    # Forward everything else into public/ where the real front controller lives.
    RewriteCond %{REQUEST_URI} !^/public/
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>

<IfModule !mod_rewrite.c>
    # Without mod_rewrite, index.php still forwards to public/index.php,
    # but static assets under /build will not resolve. Enable mod_rewrite
    # or point the document root at public/ for full functionality.
    DirectoryIndex index.php
</IfModule>
HT;
}

function root_web_config(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!-- Magna root forwarder for IIS. Requires the URL Rewrite module. -->
<configuration>
    <system.webServer>
        <rewrite>
            <rules>
                <rule name="Deny app internals" stopProcessing="true">
                    <match url="^(app|bootstrap|config|database|lang|resources|routes|schemas|src|storage|tests|vendor|\.env|\.git)(/|$)" />
                    <action type="CustomResponse" statusCode="403" statusReason="Forbidden" statusDescription="Forbidden" />
                </rule>
                <rule name="Forward to public" stopProcessing="true">
                    <match url="^(?!public/)(.*)$" />
                    <action type="Rewrite" url="public/{R:1}" />
                </rule>
            </rules>
        </rewrite>
    </system.webServer>
</configuration>
XML;
}

function install_readme(string $version): string
{
    return <<<TXT
Magna CMS v{$version} — installation
====================================

1. Upload and extract this archive to the root of your domain or subdomain
   (for example public_html/ or the subdomain's document root). After
   extraction you should see index.php, public/, and vendor/ side by side.

2. Make sure the extracted files are OWNED by the user PHP runs as.

   Control panels (Virtualmin/Webmin, cPanel, Plesk) often extract archives
   as root when you are logged in as the server admin, which leaves every
   file owned by root. PHP can then read the site but write nothing: the
   installer may still complete, and later "Update Now" will refuse to run
   because it cannot replace its own files.

   From a shell, as root, with youruser = the domain's PHP/FPM user:
     chown -R youruser: /path/to/this/directory

   Not sure which user PHP runs as? Finish the install and open
   System Insights in the admin panel — it names the user and prints the
   exact command if anything is wrong.

3. Make sure these folders are writable by the web server:
     storage/            (and everything inside it)
     bootstrap/cache/

4. Open your domain in a browser. Magna's installer runs automatically:
     - checks server requirements
     - asks for your site name and URL
     - asks for your database connection
     - creates your administrator account

That's it — no command line, no Composer needed. The installer disables
itself once setup is complete.

Advanced (server you control): for the tightest security, point your web
server's document root directly at the public/ directory. The bundled root
forwarder is only there so the "extract to the domain root" flow works on
shared hosting where you cannot change the document root.
TXT;
}
