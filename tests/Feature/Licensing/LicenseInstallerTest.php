<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Magna\Licensing\LicenseInstaller;
use Magna\Licensing\SignedPayload;
use Magna\Marketplace\Marketplace;

beforeEach(function (): void {
    $pair = sodium_crypto_sign_keypair();
    $this->signingSecret = sodium_crypto_sign_secretkey($pair);
    config(['magna.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

    $this->zipBytes = 'PK-fake-plugin-zip-bytes';
    $this->zipSha = hash('sha256', $this->zipBytes);
});

/** A download grant exactly as PackageDownloadService issues one. */
function grantFor(string $sha, string $secret, ?string $url = null): array
{
    return [
        'url' => $url ?? Marketplace::WEB_BASE.'/api/v1/license/download/nonce123?signature=abc',
        'expires_at' => now()->addMinutes(5)->toIso8601String(),
        'version' => '2.0.0',
        'sha256' => $sha,
        'sha256_signature' => base64_encode(sodium_crypto_sign_detached(
            SignedPayload::canonicalize(['sha256' => $sha]),
            $secret,
        )),
        'algorithm' => 'ed25519',
    ];
}

it('refuses a grant whose checksum signature does not verify', function (): void {
    Http::fake();

    // Checksum signed by someone else's key — a tampered or spoofed grant.
    $rogue = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

    expect(fn () => app(LicenseInstaller::class)->installFromGrant('acme/crm', grantFor($this->zipSha, $rogue)))
        ->toThrow(RuntimeException::class, 'signature verification');

    // Nothing was even downloaded — authenticity is checked before the fetch.
    Http::assertNothingSent();
});

it('refuses a grant that points outside the marketplace', function (): void {
    Http::fake();

    $grant = grantFor($this->zipSha, $this->signingSecret, 'https://evil.example.com/payload.zip');

    expect(fn () => app(LicenseInstaller::class)->installFromGrant('acme/crm', $grant))
        ->toThrow(RuntimeException::class, 'outside the Magna marketplace');

    Http::assertNothingSent();
});

it('aborts when the downloaded bytes do not match the signed checksum', function (): void {
    // Server hands over a correctly signed checksum, but the bytes on the
    // wire are something else — the substituted-archive case.
    Http::fake(['*' => Http::response('totally-different-bytes')]);

    expect(fn () => app(LicenseInstaller::class)->installFromGrant('acme/crm', grantFor($this->zipSha, $this->signingSecret)))
        ->toThrow(RuntimeException::class, 'did not match its checksum');
});

it('reports a used-up download link clearly', function (): void {
    Http::fake(['*' => Http::response('', 410)]);

    expect(fn () => app(LicenseInstaller::class)->installFromGrant('acme/crm', grantFor($this->zipSha, $this->signingSecret)))
        ->toThrow(RuntimeException::class, 'already been used');
});

it('rejects an incomplete grant', function (): void {
    expect(fn () => app(LicenseInstaller::class)->installFromGrant('acme/crm', ['url' => 'x']))
        ->toThrow(RuntimeException::class, 'incomplete');
});

it('passes authenticity and integrity, then fails on structure', function (): void {
    Http::fake(['*' => Http::response($this->zipBytes)]);

    // Signature and checksum both pass on these bytes, so the only thing left
    // to reject them is PackageExtractor — and it does, because they are not
    // actually a zip. Reaching this specific error is the assertion: it proves
    // steps 1 and 2 were satisfied and step 3 is what stopped the install.
    expect(fn () => app(LicenseInstaller::class)->installFromGrant('acme/crm', grantFor($this->zipSha, $this->signingSecret)))
        ->toThrow(RuntimeException::class, 'not a valid zip archive');
});
