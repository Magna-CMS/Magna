<?php

declare(strict_types=1);

/**
 * The archive-integrity mechanics behind a core update, testable apart from
 * the apply orchestration: host allowlisting, checksum verification, and the
 * signed-checksum policy.
 */

use Illuminate\Support\Facades\Http;
use Magna\Updater\ReleaseArchive;
use Tests\TestCase;

uses(TestCase::class);

it('accepts an https url on an allowlisted host', function (): void {
    // The request itself is faked; reaching the HTTP layer at all proves the
    // guard let the URL through.
    Http::fake(['github.com/*' => Http::response('zip bytes')]);

    $zipPath = app(ReleaseArchive::class)->download('https://github.com/magna-cms/magna/archive/refs/tags/v1.2.0.zip');

    expect(is_file($zipPath))->toBeTrue();
    @unlink($zipPath);
});

it('refuses a malformed url with no host', function (): void {
    Http::fake();

    expect(fn (): string => app(ReleaseArchive::class)->download('not-a-url'))
        ->toThrow(RuntimeException::class, 'untrusted source');

    Http::assertNothingSent();
});

it('refuses to download from a plain-http url', function (): void {
    Http::fake();

    expect(fn (): string => app(ReleaseArchive::class)->download('http://github.com/x.zip'))
        ->toThrow(RuntimeException::class, 'untrusted source');

    Http::assertNothingSent();
});

it('refuses to download from a host outside the allowlist', function (): void {
    Http::fake();

    expect(fn (): string => app(ReleaseArchive::class)->download('https://evil.example.com/x.zip'))
        ->toThrow(RuntimeException::class, 'untrusted source');

    Http::assertNothingSent();
});

it('refuses an archive whose checksum does not match', function (): void {
    $zipPath = tempnam(sys_get_temp_dir(), 'magna-release-test-');
    file_put_contents($zipPath, 'archive bytes');

    expect(fn () => app(ReleaseArchive::class)->verifyChecksum($zipPath, str_repeat('a', 64)))
        ->toThrow(RuntimeException::class, 'checksum does not match');

    @unlink($zipPath);
});

it('accepts an archive whose checksum matches', function (): void {
    $zipPath = tempnam(sys_get_temp_dir(), 'magna-release-test-');
    file_put_contents($zipPath, 'archive bytes');

    app(ReleaseArchive::class)->verifyChecksum($zipPath, hash('sha256', 'archive bytes'));

    // Reaching here without a throw is the assertion.
    expect(true)->toBeTrue();

    @unlink($zipPath);
});

it('tolerates a missing signature only while the hatch is open', function (): void {
    $archive = app(ReleaseArchive::class);
    $sha = str_repeat('b', 64);

    config(['magna.updater.allow_unsigned_checksum' => true]);
    expect($archive->checkChecksumSignature($sha, null))->toBeNull();

    config(['magna.updater.allow_unsigned_checksum' => false]);
    expect($archive->checkChecksumSignature($sha, null))
        ->toContain('without a signed checksum');
});

it('always refuses a signature that fails verification', function (): void {
    // Present-but-invalid is fatal regardless of the hatch.
    config(['magna.updater.allow_unsigned_checksum' => true]);

    expect(app(ReleaseArchive::class)->checkChecksumSignature(str_repeat('c', 64), base64_encode('garbage')))
        ->toContain('failed signature verification');
});

it('requires a signed checksum by default (W1-5)', function (): void {
    /*
     * The flip to secure-by-default: Update Manager publishes an Ed25519
     * signature for every core release, so an unsigned /updates response is
     * refused unless an operator explicitly opts out with
     * MAGNA_UPDATER_ALLOW_UNSIGNED_CHECKSUM=true. This pin stops the default
     * quietly sliding back to permissive.
     *
     * The key was `require_signed_checksum` and had to be renamed to land.
     * It shipped false before 1.4.0 and true after, but a core update never
     * replaces `config/` - so every site that UPDATED kept the old false and
     * went on accepting unsigned releases, while a fresh install of the same
     * version refused them. Changing the default could not reach them either:
     * the key was present in their file, and a present key is the site's own
     * word. A new name is absent everywhere, which is what let the safe value
     * arrive. See tests/Feature/Updater/CoreConfigDefaultsTest.php.
     */
    expect(config('magna.updater.allow_unsigned_checksum'))->toBeFalse();
});
