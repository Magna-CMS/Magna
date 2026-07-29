<?php

declare(strict_types=1);

use Magna\Updater\CoreUpdater;
use Magna\Updater\CoreUpdateState;
use Tests\TestCase;

uses(TestCase::class);

// zip_url comes straight from Update Manager's /updates response with no
// signature/checksum on the archive itself, and this class overlays it
// directly onto app/, bootstrap/, and src/Magna — the code that runs on
// every request. guardDownloadUrl() is the floor that stops a compromised
// or MITM'd response pointing this at an arbitrary host; exercised via
// reflection since it's a private guard called at the top of download().
function coreUpdaterGuard(string $zipUrl): void
{
    $updater = app(CoreUpdater::class);
    $method = new ReflectionMethod(CoreUpdater::class, 'guardDownloadUrl');
    $method->setAccessible(true);
    $method->invoke($updater, $zipUrl);
}

it('allows an https download url on an allowlisted host', function (): void {
    expect(fn () => coreUpdaterGuard('https://github.com/magna-cms/magna/archive/refs/tags/v1.2.0.zip'))
        ->not->toThrow(Throwable::class);
});

it('rejects a download url on a host not on the allowlist', function (): void {
    expect(fn () => coreUpdaterGuard('https://evil.example.com/payload.zip'))
        ->toThrow(RuntimeException::class);
});

it('rejects a plain http download url even on an allowlisted host', function (): void {
    expect(fn () => coreUpdaterGuard('http://managemagna.jrstudios.dev/releases/v1.2.0.zip'))
        ->toThrow(RuntimeException::class);
});

it('rejects a malformed url with no host', function (): void {
    expect(fn () => coreUpdaterGuard('not-a-url'))
        ->toThrow(RuntimeException::class);
});

// The host allowlist alone stops an attacker-supplied arbitrary host but
// never verified the archive itself was genuine. apply() now requires a
// valid-looking sha256 up front and fails closed without one — verified
// here without touching the network/lock/backup machinery, since a missing
// checksum must be rejected before any of that runs.
it('fails apply() immediately when no checksum is supplied', function (): void {
    $updater = app(CoreUpdater::class);

    $state = $updater->apply('9.9.9', 'https://github.com/magna-cms/magna/archive/v9.9.9.zip', null);

    expect($state)->toBe(CoreUpdateState::Failed);
    expect(CoreUpdater::progress()['message'])->toContain('no verified checksum');
});

it('fails apply() immediately when the checksum is not a valid sha256 hex string', function (): void {
    $updater = app(CoreUpdater::class);

    $state = $updater->apply('9.9.9', 'https://github.com/magna-cms/magna/archive/v9.9.9.zip', 'not-a-real-checksum');

    expect($state)->toBe(CoreUpdateState::Failed);
});

it('verifyChecksum accepts a matching sha256 and rejects a mismatch', function (): void {
    $updater = app(CoreUpdater::class);
    $method = new ReflectionMethod(CoreUpdater::class, 'verifyChecksum');
    $method->setAccessible(true);

    $tmp = tempnam(sys_get_temp_dir(), 'magna-checksum-test');
    file_put_contents($tmp, 'hello world');
    $correctHash = hash_file('sha256', $tmp);

    expect(fn () => $method->invoke($updater, $tmp, $correctHash))->not->toThrow(Throwable::class);
    expect(fn () => $method->invoke($updater, $tmp, str_repeat('a', 64)))->toThrow(RuntimeException::class);

    unlink($tmp);
});
