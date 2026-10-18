<?php

declare(strict_types=1);

/**
 * Publishes a built release to GitHub, completely or not at all.
 *
 * Usage:
 *   php bin/publish-release.php 1.5.1
 *   php bin/publish-release.php 1.5.1 --dry-run
 *   php bin/publish-release.php 1.6.0-beta --repo=Magna-CMS/Magna
 *
 * Why this exists. `.github/workflows/release.yml` publishes a tag correctly
 * and has done since it went green — but every release so far was published by
 * hand, because the workflow was red for the whole v1.3.20 … v1.4.8 stretch and
 * the habit outlived the repair. By hand means five steps remembered in order:
 * annotate the tag, push it, create the release, attach BOTH artefacts, then
 * check that what landed is what was built.
 *
 * v1.4.9 missed one of those five. Its `.sha256` sidecar was never attached,
 * and nothing noticed for days — the sidecar is the only published statement of
 * what the archive should hash to, and `sha256sum -c` is the check a human
 * performs with it. No update was affected (the updater verifies against the
 * hub record's `download_sha256`, not the release asset), which is exactly why
 * it went unnoticed: the missing piece is the one only a person reads.
 *
 * So the five steps are a script, and the script refuses rather than
 * improvising. Each refusal below is a thing that has gone wrong or could only
 * be caught by remembering:
 *
 *   - the archive or its sidecar is absent        → nothing to publish
 *   - the sidecar does not match the archive      → the pair is not a pair
 *   - the archive's own manifest names a          → the artefact is from a
 *     different version than the tag                different build
 *   - there are no release notes anywhere         → the release body would be
 *                                                   a generic install blurb
 *   - the tag already has a release               → publishing would duplicate
 *                                                   or clobber a hand-published
 *                                                   one
 *
 * Notes come from `downloads/v<version>-release-notes.md` when it exists and
 * from the annotated tag's own message when it does not — the same two sources
 * release.yml uses, in the same order, so publishing by hand and publishing by
 * tag push produce the same body.
 *
 * It ends by re-downloading the published asset and hashing it. A release whose
 * published bytes differ from the built bytes is the one failure that cannot be
 * found by reading the upload output, and it is the whole reason the sidecar
 * exists.
 *
 * Requires: a built archive in downloads/ (see bin/build-release.php), the `gh`
 * CLI authenticated for the repository, and a git remote to push the tag to.
 */
const C_RESET = "\033[0m";
const C_GREEN = "\033[32m";
const C_YELLOW = "\033[33m";
const C_RED = "\033[31m";

function publish_say(string $message, string $color = C_RESET): void
{
    fwrite(STDOUT, $color.$message.C_RESET.PHP_EOL);
}

/**
 * Stops, naming the refusal. Never a warning: a partial publish is the failure
 * this script exists to prevent, so there is no "continue anyway" path.
 */
function publish_refuse(string $reason, string $remedy = ''): never
{
    publish_say('REFUSING TO PUBLISH: '.$reason, C_RED);

    if ($remedy !== '') {
        publish_say('  '.$remedy, C_YELLOW);
    }

    exit(1);
}

/**
 * @param  list<string>  $command
 * @return array{out: string, code: int}
 */
function publish_run(array $command): array
{
    // Quoted per-argument rather than as one string: a release-notes path or a
    // repository name is data, and the shell behind this is cmd on Windows and
    // sh elsewhere.
    $line = implode(' ', array_map(
        static fn (string $part): string => preg_match('#^[A-Za-z0-9_\-./:=]+$#', $part) === 1 ? $part : escapeshellarg($part),
        $command,
    ));

    $output = [];
    $code = 0;
    exec($line.' 2>&1', $output, $code);

    return ['out' => implode(PHP_EOL, $output), 'code' => $code];
}

$root = dirname(__DIR__);
chdir($root);

// ---------------------------------------------------------------------------
// Arguments.
// ---------------------------------------------------------------------------
$version = null;
$repo = null;
$remote = 'origin';
$notesPath = null;
$dryRun = false;

foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;

        continue;
    }

    if (str_starts_with($arg, '--repo=')) {
        $repo = substr($arg, 7);

        continue;
    }

    if (str_starts_with($arg, '--remote=')) {
        $remote = substr($arg, 9);

        continue;
    }

    if (str_starts_with($arg, '--notes=')) {
        $notesPath = substr($arg, 8);

        continue;
    }

    if (str_starts_with($arg, '-')) {
        publish_refuse("Unknown option '{$arg}'.", 'Usage: php bin/publish-release.php <version> [--repo=owner/name] [--remote=origin] [--notes=path] [--dry-run]');
    }

    $version ??= $arg;
}

if ($version === null) {
    publish_refuse('No version given.', 'Usage: php bin/publish-release.php <version> [--repo=owner/name] [--remote=origin] [--notes=path] [--dry-run]');
}

$version = ltrim($version, 'vV');

if (preg_match('/^\d+\.\d+\.\d+(-[A-Za-z0-9.]+)?$/', $version) !== 1) {
    publish_refuse("'{$version}' is not a version.", 'Expected 1.5.1, or 1.6.0-beta for a pre-release.');
}

$tag = 'v'.$version;
$prerelease = str_contains($version, '-');

// The builder strips the pre-release suffix from the ARCHIVE name while the tag
// keeps it, so a beta tag publishes an archive named for its base version.
$baseVersion = preg_replace('/-.*$/', '', $version) ?? $version;

$zipPath = $root.'/downloads/magna-cms-v'.$baseVersion.'.zip';
$shaPath = $zipPath.'.sha256';

// ---------------------------------------------------------------------------
// Refusal 1: both artefacts exist.
// ---------------------------------------------------------------------------
if (! is_file($zipPath)) {
    publish_refuse('No archive at downloads/'.basename($zipPath).'.', "Build it first: php bin/build-release.php {$baseVersion}");
}

if (! is_file($shaPath)) {
    // The 1.4.9 failure, caught before it reaches GitHub rather than days
    // after.
    publish_refuse(
        'No checksum sidecar at downloads/'.basename($shaPath).'.',
        'The builder writes it beside the archive; a release published without it has no verifiable hash. Rebuild rather than hand-write one.'
    );
}

// ---------------------------------------------------------------------------
// Refusal 2: the sidecar describes THIS archive.
// ---------------------------------------------------------------------------
$sidecar = (string) file_get_contents($shaPath);
$declaredHash = strtolower(trim(explode(' ', trim($sidecar))[0] ?? ''));
$actualHash = (string) hash_file('sha256', $zipPath);

if ($declaredHash !== $actualHash) {
    publish_refuse(
        'The sidecar does not match the archive.',
        "sidecar {$declaredHash}, archive {$actualHash} — one of the two is from a different build. Rebuild."
    );
}

// ---------------------------------------------------------------------------
// Refusal 3: the archive's own manifest agrees with the tag.
// ---------------------------------------------------------------------------
// Read from inside the ZIP, because the manifest in the build tree can have
// been regenerated since the archive was written. The artefact has to speak for
// itself.
if (! extension_loaded('zip')) {
    publish_refuse('The zip extension is not loaded, so the archive cannot be checked against the tag.');
}

$zip = new ZipArchive;

if ($zip->open($zipPath) !== true) {
    publish_refuse('Cannot open '.basename($zipPath).'.');
}

$manifestRaw = $zip->getFromName('magna-release.json');
$zip->close();

if (! is_string($manifestRaw) || $manifestRaw === '') {
    publish_refuse(
        basename($zipPath).' carries no magna-release.json.',
        'Every archive the builder produces has one; this is not a builder archive.'
    );
}

$manifest = json_decode($manifestRaw, true);
$manifestVersion = is_array($manifest) && is_string($manifest['version'] ?? null) ? $manifest['version'] : null;

if ($manifestVersion === null) {
    publish_refuse('magna-release.json inside the archive names no version.');
}

if ($manifestVersion !== $baseVersion) {
    publish_refuse(
        "The archive is version {$manifestVersion}, the tag is {$tag}.",
        'Publishing would attach one version under another version\'s release. Rebuild for this tag.'
    );
}

// ---------------------------------------------------------------------------
// Refusal 4: there are release notes.
// ---------------------------------------------------------------------------
$notesFile = $notesPath ?? $root.'/downloads/v'.$version.'-release-notes.md';
$notes = is_file($notesFile) ? trim((string) file_get_contents($notesFile)) : '';
$notesSource = $notes === '' ? '' : 'downloads/'.basename($notesFile);

if ($notes === '') {
    // Same fallback order as release.yml: the annotated tag's own message. A
    // lightweight tag yields nothing, which is the refusal.
    $annotation = publish_run(['git', 'tag', '-l', '--format=%(contents)', $tag]);
    $fromTag = trim(preg_replace('/-----BEGIN PGP SIGNATURE-----.*$/s', '', $annotation['out']) ?? '');

    if ($fromTag !== '') {
        $notes = $fromTag;
        $notesSource = "the {$tag} tag annotation";
    }
}

if ($notes === '') {
    publish_refuse(
        'No release notes.',
        "Write downloads/v{$version}-release-notes.md, or annotate the tag: git tag -a {$tag} -m '…'"
    );
}

// ---------------------------------------------------------------------------
// Refusal 5: the tag has no release yet.
// ---------------------------------------------------------------------------
if (publish_run(['gh', '--version'])['code'] !== 0) {
    publish_refuse('The gh CLI is not available.', 'Install it and run `gh auth login`; publishing needs it for both the release and the verification download.');
}

$repoArgs = $repo === null ? [] : ['--repo', $repo];

$existing = publish_run(array_merge(['gh', 'release', 'view', $tag], $repoArgs, ['--json', 'tagName']));

if ($existing['code'] === 0) {
    // Most tags before v1.4.9 already have a hand-published release, and
    // re-publishing one would either duplicate assets or replace what is
    // already downloaded. release.yml refuses the same case for the same
    // reason.
    publish_refuse(
        "A release for {$tag} already exists.",
        'Delete it deliberately if it must be replaced, or publish the next version instead.'
    );
}

// ---------------------------------------------------------------------------
// Publish.
// ---------------------------------------------------------------------------
publish_say("Publishing {$tag}", C_GREEN);
publish_say('  archive  downloads/'.basename($zipPath).' ('.number_format((int) filesize($zipPath) / 1048576, 1).' MB)');
publish_say('  sha256   '.$actualHash);
publish_say('  notes    '.$notesSource);
publish_say('  release  '.($prerelease ? 'pre-release' : 'stable'));

if ($dryRun) {
    publish_say('Dry run: every check passed, nothing was pushed or created.', C_GREEN);

    exit(0);
}

$tagExists = publish_run(['git', 'rev-parse', '-q', '--verify', 'refs/tags/'.$tag])['code'] === 0;

if (! $tagExists) {
    publish_say("Annotating {$tag}");

    $messageFile = tempnam(sys_get_temp_dir(), 'magna-tag-');

    if ($messageFile === false) {
        publish_refuse('Cannot write a temporary file for the tag message.');
    }

    file_put_contents($messageFile, $notes.PHP_EOL);
    $tagged = publish_run(['git', 'tag', '-a', $tag, '-F', $messageFile]);
    @unlink($messageFile);

    if ($tagged['code'] !== 0) {
        publish_refuse("Could not create the tag: {$tagged['out']}");
    }
}

publish_say("Pushing {$tag} to {$remote}");
$pushed = publish_run(['git', 'push', $remote, 'refs/tags/'.$tag]);

if ($pushed['code'] !== 0) {
    publish_refuse("Could not push the tag: {$pushed['out']}");
}

publish_say('Creating the release and attaching both artefacts');

// Through a file, not --notes: the body is multi-line markdown, and a newline
// inside an argument is not something cmd.exe can carry.
$bodyFile = tempnam(sys_get_temp_dir(), 'magna-notes-');

if ($bodyFile === false) {
    publish_refuse('Cannot write a temporary file for the release body.');
}

file_put_contents($bodyFile, $notes.PHP_EOL);

$created = publish_run(array_merge(
    ['gh', 'release', 'create', $tag],
    $repoArgs,
    ['--title', 'Magna CMS '.$tag, '--notes-file', $bodyFile],
    $prerelease ? ['--prerelease'] : [],
    [$zipPath, $shaPath],
));

@unlink($bodyFile);

if ($created['code'] !== 0) {
    publish_refuse("gh release create failed: {$created['out']}");
}

// ---------------------------------------------------------------------------
// Verify what landed, rather than what was sent.
// ---------------------------------------------------------------------------
$assets = publish_run(array_merge(['gh', 'release', 'view', $tag], $repoArgs, ['--json', 'assets']));
$assetNames = [];

if ($assets['code'] === 0) {
    $decoded = json_decode($assets['out'], true);

    foreach (is_array($decoded) && is_array($decoded['assets'] ?? null) ? $decoded['assets'] : [] as $asset) {
        if (is_array($asset) && is_string($asset['name'] ?? null)) {
            $assetNames[] = $asset['name'];
        }
    }
}

foreach ([basename($zipPath), basename($shaPath)] as $required) {
    if (! in_array($required, $assetNames, true)) {
        publish_refuse(
            "The release was created but {$required} is not attached to it.",
            'Attach it now: gh release upload '.$tag.' downloads/'.$required
        );
    }
}

publish_say('Both artefacts are attached. Re-downloading the archive to hash it');

$verifyDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'magna-publish-'.getmypid();

if (! is_dir($verifyDir) && ! mkdir($verifyDir, 0755, true) && ! is_dir($verifyDir)) {
    publish_refuse('Cannot create a directory to verify the download in.');
}

$download = publish_run(array_merge(
    ['gh', 'release', 'download', $tag],
    $repoArgs,
    ['--pattern', basename($zipPath), '--dir', $verifyDir],
));

$downloaded = $verifyDir.DIRECTORY_SEPARATOR.basename($zipPath);

if ($download['code'] !== 0 || ! is_file($downloaded)) {
    publish_refuse("The published asset could not be downloaded back: {$download['out']}");
}

$publishedHash = (string) hash_file('sha256', $downloaded);
@unlink($downloaded);
@rmdir($verifyDir);

if ($publishedHash !== $actualHash) {
    publish_refuse(
        'The published archive does not hash to the sidecar.',
        "published {$publishedHash}, sidecar {$actualHash} — the upload is corrupt. Delete the release and publish again."
    );
}

publish_say('', C_RESET);
publish_say("Published {$tag}: both artefacts attached, published hash verified against the sidecar.", C_GREEN);
