<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Magna\Licensing\LicenseInstaller;
use Magna\Licensing\SignedPayload;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\Marketplace;
use Magna\Plugins\PluginDiscovery;
use Magna\Plugins\PluginRecord;
use Symfony\Component\Filesystem\Filesystem;

// Tests\TestCase is bound to this folder already; only the database
// refresh has to be asked for.
uses(RefreshDatabase::class);

/**
 * Regression: installing a licensed plugin from the Magna Account page failed
 * with the plugin already on disk.
 *
 *     Plugin [roya/erp] was not found. Run `composer require roya/erp` first.
 *
 * PluginDiscovery memoizes its scan for the length of a request, and the page
 * that submits the install has already triggered one. The freshly written
 * directory was therefore invisible: syncDiscovered() recorded nothing and
 * enable() reported the plugin missing — telling the admin to run Composer for
 * something that was sitting in plugins-dev/.
 *
 * Every other test in LicenseInstallerTest stops at authenticity or structure,
 * so none of them ever placed a real package. That is why this reached a
 * customer install.
 */
beforeEach(function (): void {
    $pair = sodium_crypto_sign_keypair();
    $this->signingSecret = sodium_crypto_sign_secretkey($pair);
    config(['magna.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

    $this->package = 'acme/licensed-widget';
    $this->target = base_path('plugins-dev/acme/licensed-widget');

    // A real archive: the installer extracts it, reads magna.json and checks
    // the name matches the licence before writing anything.
    $this->zipPath = tempnam(sys_get_temp_dir(), 'magna-licensed-').'.zip';

    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('magna.json', json_encode([
        'name' => $this->package,
        'displayName' => 'Licensed Widget',
        'description' => 'A fixture that exists only inside this test.',
        'version' => '2.0.0',
        'author' => 'Acme',
        'license' => 'proprietary',
        'entry' => 'Acme\\LicensedWidget\\WidgetPlugin',
        'compat' => ['magna' => MagnaServiceProvider::VERSION, 'php' => '^8.3'],
        'provides' => [],
        'permissions' => [],
    ]));
    $zip->close();

    $this->zipBytes = (string) file_get_contents($this->zipPath);
    $this->zipSha = hash('sha256', $this->zipBytes);
});

afterEach(function (): void {
    $files = new Filesystem;
    $files->remove([$this->zipPath, $this->target]);
    $files->remove(base_path('plugins-dev/acme'));
});

/** A download grant exactly as PackageDownloadService issues one. */
function licensedGrant(string $sha, string $secret): array
{
    return [
        'url' => Marketplace::WEB_BASE.'/api/v1/license/download/nonce123?signature=abc',
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

it('finds a licensed plugin it has just written, even with discovery already warm', function (): void {
    Http::fake(['*' => Http::response($this->zipBytes)]);

    $discovery = app(PluginDiscovery::class);

    // What the Magna Account page does before the install runs. This is the
    // state the bug needed: a memoized scan taken before the files existed.
    $discovery->discover();
    expect($discovery->find($this->package))->toBeNull();

    try {
        app(LicenseInstaller::class)->installFromGrant($this->package, licensedGrant($this->zipSha, $this->signingSecret));
    } catch (Throwable $e) {
        // enable() instantiates the entry class, which this fixture does not
        // ship — so a failure there is expected and fine. What must NOT happen
        // is the plugin going missing.
        expect($e->getMessage())->not->toContain('was not found');
    }

    // Discovery still will not return it, and that is correct: plugins-dev/ is
    // only discoverable when wired in as a Composer path repository, and never
    // in production. The record is what enable() and bootEnabledPlugins() both
    // actually read, so the record is what has to exist.
    expect(is_file($this->target.'/magna.json'))->toBeTrue()
        ->and(PluginRecord::query()->where('name', $this->package)->exists())->toBeTrue();

    $record = PluginRecord::query()->where('name', $this->package)->firstOrFail();

    expect($record->base_path)->toBe($this->target)
        ->and($record->version)->toBe('2.0.0');
});

it('refuses a package whose manifest names a different product', function (): void {
    Http::fake(['*' => Http::response($this->zipBytes)]);

    // A licence for one product must never install another.
    expect(fn () => app(LicenseInstaller::class)
        ->installFromGrant('acme/something-else', licensedGrant($this->zipSha, $this->signingSecret)))
        ->toThrow(RuntimeException::class, 'not "acme/something-else"');

    expect(is_dir($this->target))->toBeFalse();
});
