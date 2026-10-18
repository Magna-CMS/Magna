<?php

declare(strict_types=1);

/**
 * The manual publish path refuses an incomplete release.
 *
 * Every release so far was published by hand, because release.yml was red for
 * the whole v1.3.20 … v1.4.8 stretch and the habit outlived the repair. v1.4.9
 * was published that way and its `.sha256` sidecar was never attached — the one
 * step out of five that nothing downstream notices, because the updater
 * verifies against the hub record rather than the release asset. It stayed
 * missing for days.
 *
 * bin/publish-release.php is that path as a script, and these are its
 * refusals. Each one is exercised against the real script in a real
 * subprocess with real fixture artefacts, because the thing being tested is
 * what the script does with a half-built release on disk — not what a
 * function returns.
 *
 * Version 9.9.9 throughout: it can never collide with a real archive, and
 * downloads/ is gitignored, so the fixtures leave nothing behind.
 */
function publishFixturePath(string $suffix = ''): string
{
    return dirname(__DIR__, 3).'/downloads/magna-cms-v9.9.9.zip'.$suffix;
}

/** Writes a builder-shaped archive whose manifest claims $manifestVersion. */
function writePublishFixtureZip(string $manifestVersion): void
{
    $zip = new ZipArchive;
    $zip->open(publishFixturePath(), ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('magna-release.json', json_encode(['version' => $manifestVersion], JSON_THROW_ON_ERROR));
    $zip->close();
}

function writePublishFixtureSidecar(?string $hash = null): void
{
    $hash ??= (string) hash_file('sha256', publishFixturePath());

    file_put_contents(publishFixturePath('.sha256'), $hash.'  magna-cms-v9.9.9.zip'."\n");
}

/** @return array{out: string, code: int} */
function runPublishRelease(string ...$arguments): array
{
    $command = escapeshellarg(PHP_BINARY).' '
        .escapeshellarg(dirname(__DIR__, 3).'/bin/publish-release.php').' '
        .implode(' ', array_map(escapeshellarg(...), $arguments)).' 2>&1';

    $output = [];
    $code = 0;
    exec($command, $output, $code);

    return ['out' => implode("\n", $output), 'code' => $code];
}

afterEach(function (): void {
    @unlink(publishFixturePath());
    @unlink(publishFixturePath('.sha256'));
});

it('refuses without a version', function (): void {
    $result = runPublishRelease();

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('No version given');
});

it('refuses something that is not a version', function (): void {
    $result = runPublishRelease('latest');

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain("'latest' is not a version");
});

it('refuses when the archive has not been built', function (): void {
    $result = runPublishRelease('9.9.9');

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('No archive at downloads/magna-cms-v9.9.9.zip')
        ->and($result['out'])->toContain('bin/build-release.php');
});

it('refuses when the checksum sidecar is missing', function (): void {
    // The v1.4.9 failure itself, one step earlier than it happened.
    writePublishFixtureZip('9.9.9');

    $result = runPublishRelease('9.9.9');

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('No checksum sidecar at downloads/magna-cms-v9.9.9.zip.sha256');
});

it('refuses when the sidecar does not describe the archive', function (): void {
    writePublishFixtureZip('9.9.9');
    writePublishFixtureSidecar(str_repeat('0', 64));

    $result = runPublishRelease('9.9.9');

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('The sidecar does not match the archive');
});

it('refuses when the archive is a different version from the tag', function (): void {
    // Read from inside the ZIP: the manifest in the build tree can have been
    // regenerated since, so the artefact has to speak for itself.
    writePublishFixtureZip('1.2.3');
    writePublishFixtureSidecar();

    $result = runPublishRelease('9.9.9');

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('The archive is version 1.2.3, the tag is v9.9.9');
});

it('refuses when there are no release notes in either place', function (): void {
    writePublishFixtureZip('9.9.9');
    writePublishFixtureSidecar();

    // No downloads/v9.9.9-release-notes.md, and no v9.9.9 tag to annotate, so
    // the body would be the generic install blurb and the release would say
    // nothing about itself.
    $result = runPublishRelease('9.9.9');

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('No release notes');
});

it('reads notes from a file when one is given', function (): void {
    writePublishFixtureZip('9.9.9');
    writePublishFixtureSidecar();

    $notes = tempnam(sys_get_temp_dir(), 'magna-notes-test-');
    expect($notes)->toBeString();

    /** @var string $notes */
    file_put_contents($notes, 'Fixture notes.');

    $result = runPublishRelease('9.9.9', '--notes='.$notes, '--dry-run');
    @unlink($notes);

    // Past every local refusal. Whether it then reaches GitHub depends on gh
    // being installed and authenticated, which a test must not require — so
    // the assertion is only that the notes refusal is no longer the answer.
    expect($result['out'])->not->toContain('No release notes');
});

/**
 * The last two steps cannot be exercised without publishing something, so they
 * are pinned the way ReleaseGatesOrderTest pins the gate order: by reading the
 * script. Both exist because of a specific near-miss — a tag that already had a
 * hand-published release, and an upload whose bytes nobody compared with the
 * sidecar afterwards.
 */
it('checks for an existing release before creating one', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/publish-release.php');

    $check = strpos($script, "'gh', 'release', 'view'");
    $create = strpos($script, "'gh', 'release', 'create'");

    expect($check)->not->toBeFalse('The existing-release check is gone.')
        ->and($create)->not->toBeFalse('The script no longer creates a release.')
        ->and($check)->toBeLessThan($create)
        ->and($script)->toContain('already exists');
});

it('attaches both artefacts and verifies the published hash', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/publish-release.php');

    expect($script)
        // Both files in the create call, so the pair cannot be split again.
        ->toContain('[$zipPath, $shaPath]')
        // Then what LANDED, not what was sent.
        ->toContain("'gh', 'release', 'download'")
        ->toContain('$publishedHash !== $actualHash');
});
