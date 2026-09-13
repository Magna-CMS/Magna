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

it('tolerates a missing signature only while the flag is off', function (): void {
    $archive = app(ReleaseArchive::class);
    $sha = str_repeat('b', 64);

    config(['magna.updater.require_signed_checksum' => false]);
    expect($archive->checkChecksumSignature($sha, null))->toBeNull();

    config(['magna.updater.require_signed_checksum' => true]);
    expect($archive->checkChecksumSignature($sha, null))
        ->toContain('without a signed checksum');
});

it('always refuses a signature that fails verification', function (): void {
    // Present-but-invalid is fatal regardless of the flag.
    config(['magna.updater.require_signed_checksum' => false]);

    expect(app(ReleaseArchive::class)->checkChecksumSignature(str_repeat('c', 64), base64_encode('garbage')))
        ->toContain('failed signature verification');
});
