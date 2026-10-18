<?php

declare(strict_types=1);

/**
 * Creates a git worktree of this repository that can actually run the gates.
 *
 * Usage:
 *   php bin/session-worktree.php ../magna-cms-scratch
 *   php bin/session-worktree.php ../magna-cms-rel-1.4.9 --ref=v1.4.8 --with-plugin-wiring
 *   php bin/session-worktree.php ../wt --with-plugin-wiring --with-plugins=magna,roya
 *
 * --with-plugin-wiring implies --with-plugins=magna, because wiring to plugins
 * the worktree does not contain is the one combination that cannot work — see
 * the note beside the default below.
 *
 * Why this exists. Several sessions work in this repository at once, and they
 * collide in ways that cost hours rather than minutes:
 *
 *   - The pre-push hook checks the WORKING TREE, not the commits being pushed,
 *     so one session's uncommitted file blocks everybody's push. A release was
 *     held up by a class eight lines over its architecture pin that belonged to
 *     another session entirely.
 *   - The duplication ledger and the big-class pins are read from disk too, so
 *     a half-finished edit anywhere fails a gate run everywhere.
 *   - A plugin under plugins-dev being rewritten mid-run killed a two-hour
 *     release suite with "Cannot redeclare function DB_fill_nulls()".
 *
 * A worktree fixes all three, and `git worktree add` on its own does not: a
 * fresh one fails dozens of Feature tests until it is provisioned like the main
 * checkout, because most of what the suite needs is gitignored. Every step
 * below is a failure somebody has already had.
 *
 * Not isolated by this, and worth knowing: CPU. A worktree does not create
 * cores, so a suite here still competes with a suite there — that is what
 * bin/suite-lock.php is for. The analyser caches ARE isolated, because
 * phpstan.neon.dist and psalm.xml keep them under build/ in each checkout.
 */
$root = dirname(__DIR__);

$argv = $_SERVER['argv'] ?? [];
array_shift($argv);

$target = null;
$ref = 'HEAD';
$withWiring = false;
$pluginVendors = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--ref=')) {
        $ref = substr($arg, 6);

        continue;
    }

    if ($arg === '--with-plugin-wiring') {
        $withWiring = true;

        continue;
    }

    if (str_starts_with($arg, '--with-plugins=')) {
        $pluginVendors = array_values(array_filter(explode(',', substr($arg, 15))));

        continue;
    }

    if (str_starts_with($arg, '-')) {
        worktree_fail("Unknown option: {$arg}");
    }

    if ($target !== null) {
        worktree_fail('Give one target path.');
    }

    $target = $arg;
}

if ($target === null) {
    worktree_fail('Usage: php bin/session-worktree.php <path> [--ref=<commit>] [--with-plugin-wiring] [--with-plugins=vendor,...]');
}

/*
 * Wiring without sources is the one combination that cannot work, so asking for
 * it asks for both. Two separate failures came out of the gap, and both are
 * deterministic rather than flaky:
 *
 *   - phpunit.xml's FirstPartyPlugins suite lists six bare relative
 *     directories, resolved against the worktree root, so the suite aborts
 *     before its first test on `Test directory plugins-dev/magna/blog/tests not
 *     found` — blog being alphabetically first.
 *   - A path repository that is absent here is pointed at the main checkout
 *     (below), so Composer symlinks vendor/magna-cms/marketplace OUTSIDE this
 *     worktree. PluginSource::resolve anchors on realpath starting with
 *     base_path().'/vendor/' and throws otherwise, isUpdatable() catches and
 *     returns false, and CorePluginManagerListingTest fails asserting true. The
 *     test is right: from this root the plugin genuinely is not resolvable.
 *
 * `magna` rather than every vendor: the first-party plugins are what core's own
 * suites reach for, and the client products are several GB with their own gates.
 * Those stay pointed at the main checkout, which is sound as long as nothing in
 * core's suite resolves one of them from vendor/ — pass them explicitly if that
 * ever changes.
 */
if ($withWiring && $pluginVendors === null) {
    $pluginVendors = ['magna'];
}

$target = worktree_absolute($target, getcwd() ?: $root);

if (is_dir($target)) {
    worktree_fail("{$target} already exists.");
}

worktree_say("Creating worktree at {$target} from {$ref}");
worktree_run(['git', '-C', $root, 'worktree', 'add', '--detach', $target, $ref]);

/*
 * plugins-dev is gitignored and every plugin is its own repository, so a
 * worktree has none. Copied rather than junctioned: a junction makes the
 * worktree share one tree with every other session, which is the thing being
 * escaped — and it also broke pest-plugin-arch, whose test names resolved
 * against the junction's target and asserted
 * `assert(!$test instanceof HigherOrderTapProxy)`.
 *
 * Opt-in by vendor, and nothing by default. `--with-plugins=magna` is the
 * useful one: the first-party plugins without the client products, which are
 * several times larger, and one of which (lekha) is a symlink to a separate
 * product whose tests need their own Postgres server.
 */
if ($pluginVendors !== null) {
    foreach ($pluginVendors as $vendor) {
        $from = $root.'/plugins-dev/'.$vendor;

        if (! is_dir($from)) {
            worktree_say("  skip (missing): plugins-dev/{$vendor}");

            continue;
        }

        worktree_say("  copying plugins-dev/{$vendor}/");
        worktree_copy($from, $target.'/plugins-dev/'.$vendor, ['.git', 'node_modules', 'vendor']);
    }
}

/*
 * Gitignored, and the suite needs all of it:
 *   public/build  — the Vite manifest. build-release.php fails outright without
 *                   it, and the panel page tests render views that read it.
 *   .env          — the System Info debug-window test reads the real file.
 *   themes/       — ships magna/launch, which the Pages theme tests activate.
 */
foreach (['public/build', 'themes'] as $dir) {
    if (is_dir($root.'/'.$dir)) {
        worktree_say("  copying {$dir}/");
        worktree_copy($root.'/'.$dir, $target.'/'.$dir);
    }
}

if (is_file($root.'/.env')) {
    worktree_say('  copying .env');
    copy($root.'/.env', $target.'/.env');
}

/*
 * The plugin path repositories and the @dev requires live ONLY as a local,
 * skip-worktree'd modification of the main checkout's composer.json, so they
 * can never be committed. A worktree therefore gets the TRACKED file, which
 * has no path repositories at all. That is the right default: a release is built from the
 * tracked manifest, and plugin tests skip rather than fail.
 *
 * With --with-plugin-wiring the local pair is copied in, because
 * PluginDiscovery::discoverFromDev() only trusts plugins-dev directories that
 * are listed as path repositories in the root composer.json — without it the
 * plugin tests report "Plugin [magna/forms] was not found" rather than
 * skipping, and the wiring has to be in place while the tests RUN, not merely
 * for the install.
 *
 * Every relative path repository is made absolute, because a relative one
 * resolves against the checkout and a worktree is somewhere else: composer
 * refused the install outright with 'Source path "plugins-dev/embhas/embhas" is
 * not found for package embhas/embhas'. Each is pointed at the copy inside this
 * worktree when there is one and at the main checkout when there is not, so the
 * vendors asked for are genuinely isolated and the rest still resolve instead
 * of breaking the install.
 */
if ($withWiring) {
    foreach (['composer.json', 'composer.lock'] as $file) {
        $contents = file_get_contents($root.'/'.$file);

        if ($contents === false) {
            worktree_fail("Cannot read {$root}/{$file}.");
        }

        $rewritten = preg_replace_callback(
            '#"(\.\./[^"]+|plugins-dev/[^"]+)"#',
            static function (array $match) use ($root, $target): string {
                $relative = $match[1];

                // Present in the worktree: leave it RELATIVE. Not cosmetic —
                // PluginDiscovery::composerPathRepositoryDirs() resolves each
                // path repository as basePath.'/'.url, so an absolute url
                // concatenates into nonsense, realpath() fails, and the plugin
                // is never matched against its own manifest. Rewriting these
                // cost five FormsTest errors reading "Plugin [magna/forms] was
                // not found" in a worktree where the plugin was right there.
                if (is_dir($target.'/'.$relative)) {
                    return $match[0];
                }

                // Absent: point at the main checkout, absolutely, so composer
                // can still resolve the package. Discovery will not match it,
                // which is correct — the source is not in this worktree, so
                // tests for it should skip rather than run against a tree
                // another session is editing.
                //
                // Discovery is not the only thing that reads these, though,
                // which is why --with-plugin-wiring now brings the magna
                // vendor with it: Composer symlinks vendor/{package} at an
                // absolute url, so the link leaves this worktree, and a
                // resolver anchored on base_path() (PluginSource::resolve)
                // then correctly refuses a plugin that is genuinely outside
                // the root it was asked about. Absent packages must therefore
                // be ones nothing resolves from vendor/.
                $resolved = realpath($root.'/'.$relative);

                return $resolved === false
                    ? $match[0]
                    : '"'.str_replace(DIRECTORY_SEPARATOR, '/', $resolved).'"';
            },
            $contents,
        );

        // Written without a BOM, deliberately. PowerShell's
        // `Set-Content -Encoding utf8` writes one and composer then refuses the
        // file outright: '"./composer.json" does not contain valid JSON / BOM
        // detected'. The same round trip also mangles non-ASCII in the
        // description field.
        worktree_write($target.'/'.$file, is_string($rewritten) ? $rewritten : $contents);
    }

    /*
     * And then the lock's content-hash, which nothing used to recompute.
     *
     * The hash is of composer.json, and every rewritten path-repository url
     * changes the file it hashes — so a wired worktree was born with a drifted
     * lock BY CONSTRUCTION. `composer install` only warns about that, which is
     * why it went unnoticed, but `composer validate` fails, and that is gate 1
     * of 7 in a release build: 40 minutes of setup to be told the manifest and
     * the lock disagree.
     *
     * Deleting the lock instead — which is what bin/build-release.php does for
     * the staged copy — does NOT work here, and the failure is worth recording
     * because it looks like the obvious fix. Without a lock Composer re-resolves,
     * and a copied plugin has no .git of its own (deliberately: a junction would
     * undo the isolation), so Composer falls back to the ROOT package's version
     * for every path package. The root here is a detached worktree, so they all
     * become dev-<sha> and the install dies on
     * `Root composer.json requires magna/plugin-manager dev-main, found
     * magna/plugin-manager[dev-6d0e3966…] but it does not match the constraint`.
     *
     * So the lock is kept, from-lock and identical to the main checkout's
     * resolution, and only its hash is corrected. The algorithm is Composer's
     * own (Locker::getContentHash), reimplemented here because no CLI command
     * exposes it — and checked against the main checkout's real pair first, so a
     * future change to it cannot silently produce a hash that is merely
     * different. If the check fails the lock is left alone and said so, because
     * a warning from `composer validate` is a far smaller problem than a
     * worktree that cannot install.
     */
    $mainJson = (string) file_get_contents($root.'/composer.json');
    $mainLock = (string) file_get_contents($root.'/composer.lock');
    $mainHash = worktree_content_hash($mainJson);

    if ($mainHash !== null && preg_match('/"content-hash":\s*"([^"]*)"/', $mainLock, $match) === 1 && $match[1] === $mainHash) {
        $targetJson = (string) file_get_contents($target.'/composer.json');
        $targetLock = (string) file_get_contents($target.'/composer.lock');
        $targetHash = worktree_content_hash($targetJson);

        $patched = $targetHash === null ? null : preg_replace(
            '/"content-hash":\s*"[^"]*"/',
            '"content-hash": "'.$targetHash.'"',
            $targetLock,
            1,
        );

        if (is_string($patched)) {
            worktree_write($target.'/composer.lock', $patched);
            worktree_say('  composer.lock content-hash recomputed for the rewritten manifest');
        }
    } else {
        worktree_say('  NOTE: could not reproduce composer.lock\'s content-hash, so it is left as copied.');
        worktree_say('        `composer install` still works; `composer validate` will report it as out of date.');
    }

    worktree_say('  copied the local plugin wiring, absent path repositories made absolute');
}

worktree_say('Installing dependencies');
worktree_run(['composer', 'install', '--no-interaction', '--no-progress'], $target);

worktree_say('');
worktree_say("Worktree ready: {$target}");
worktree_say('  php artisan test --testsuite=Unit,Feature');
worktree_say('  php bin/suite-lock.php --name=suite --timeout=0 -- php artisan test --testsuite=Unit,Feature,FirstPartyPlugins');
worktree_say('');
// The split is deliberate and documented in the repository guide: a worktree exists so one
// session's dirty file cannot fail everybody's push, and the plugin gate of
// record is the main checkout (bin/plugin-gates.php refuses to print a tick in
// a linked worktree, because larastan reports fictions here).
worktree_say('Commit and push from here; run a release build in the main checkout.');
worktree_say('');
worktree_say('Remove it with: git worktree remove '.$target);

/**
 * @param  list<string>  $command
 */
function worktree_run(array $command, ?string $cwd = null): void
{
    $line = implode(' ', array_map(
        static fn (string $part): string => preg_match('/[\s"]/', $part) === 1 ? escapeshellarg($part) : $part,
        $command,
    ));

    $previous = getcwd();

    if ($cwd !== null && ! chdir($cwd)) {
        worktree_fail("Cannot enter {$cwd}.");
    }

    passthru($line, $exitCode);

    if (is_string($previous)) {
        chdir($previous);
    }

    if ($exitCode !== 0) {
        worktree_fail("Command failed: {$line}");
    }
}

/**
 * Recursive copy that never follows a reparse point, so a junction or symlink
 * inside the source is skipped rather than dereferenced. Copying through them
 * once dragged 1.5 GB of plugin trees into a worktree's vendor directory.
 *
 * @param  list<string>  $exclude
 */
function worktree_copy(string $from, string $to, array $exclude = []): void
{
    if (! is_dir($to) && ! mkdir($to, 0755, true) && ! is_dir($to)) {
        worktree_fail("Cannot create {$to}.");
    }

    foreach (new DirectoryIterator($from) as $entry) {
        if ($entry->isDot()) {
            continue;
        }

        $name = $entry->getFilename();

        if (in_array($name, $exclude, true)) {
            continue;
        }

        if ($entry->isLink()) {
            continue;
        }

        $source = $entry->getPathname();

        if ($entry->isDir()) {
            // A Windows junction is a directory with a reparse point; isLink()
            // does not report one, and recursing into it copies the target.
            if (worktree_is_reparse_point($source)) {
                continue;
            }

            worktree_copy($source, $to.'/'.$name, $exclude);

            continue;
        }

        copy($source, $to.'/'.$name);
    }
}

function worktree_is_reparse_point(string $path): bool
{
    $parent = realpath(dirname($path));
    $resolved = realpath($path);

    if (! is_string($parent) || ! is_string($resolved)) {
        return false;
    }

    // Where this entry WOULD resolve if it were an ordinary directory. A
    // junction resolves somewhere else entirely. Compared case-insensitively,
    // because Windows paths are, and a false positive here would silently
    // skip a real directory.
    $expected = $parent.DIRECTORY_SEPARATOR.basename($path);

    return strcasecmp($resolved, $expected) !== 0;
}

/**
 * composer.lock's `content-hash` for a given composer.json text.
 *
 * Composer\Package\Locker::getContentHash, reimplemented: md5 of the
 * relevant keys only, sorted, json_encode'd with no options. Nothing in the
 * Composer CLI prints it, and `composer update --lock` would re-resolve rather
 * than re-hash. The caller checks this against a known-good pair before
 * trusting it.
 */
function worktree_content_hash(string $composerJson): ?string
{
    $content = json_decode($composerJson, true);

    if (! is_array($content)) {
        return null;
    }

    $relevant = [];

    foreach ([
        'name', 'version', 'require', 'require-dev', 'conflict', 'replace',
        'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra',
    ] as $key) {
        if (isset($content[$key])) {
            $relevant[$key] = $content[$key];
        }
    }

    if (isset($content['config']['platform'])) {
        $relevant['config']['platform'] = $content['config']['platform'];
    }

    ksort($relevant);

    $encoded = json_encode($relevant);

    return $encoded === false ? null : md5($encoded);
}

function worktree_write(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        worktree_fail("Cannot write {$path}.");
    }
}

function worktree_absolute(string $path, string $cwd): string
{
    $isAbsolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    $full = $isAbsolute ? $path : $cwd.'/'.$path;

    return str_replace(DIRECTORY_SEPARATOR, '/', $full);
}

function worktree_say(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function worktree_fail(string $message): never
{
    fwrite(STDERR, 'session-worktree: '.$message.PHP_EOL);

    exit(1);
}
