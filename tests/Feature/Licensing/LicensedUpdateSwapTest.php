<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Magna\Licensing\LicenseInstaller;
use Magna\Licensing\SignedPayload;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\Marketplace;
use Magna\Plugins\PluginRecord;
use Symfony\Component\Filesystem\Filesystem;

uses(RefreshDatabase::class);

/**
 * Regression: a licensed UPDATE used to park the displaced plugin as a
 * VISIBLE sibling ({name}.replacing-{rand}) while the swap ran. A process
 * killed mid-swap left the plugin directory gone entirely, and the stray
 * sibling sat exactly where the Composer path-repository glob
 * (plugins-dev/&#42;/&#42;) matches — a duplicate package definition that broke
 * every composer command. One interrupted update destroyed a working
 * roya/erp install this way. The swap now stages dot-prefixed inside the
 * parent (glob-invisible) and must leave the parent clean on success.
 */
beforeEach(function (): void {
    $pair = sodium_crypto_sign_keypair();
    $this->signingSecret = sodium_crypto_sign_secretkey($pair);
    config(['magna.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

    // Fully isolated plugins root per test — never the repository's real
    // plugins-dev/ (see LicensedUpdateMigrationTest for why).
    $this->pluginsRoot = storage_path('framework/testing/plugins-dev-'.uniqid());
    config(['magna.plugins.dev_path' => $this->pluginsRoot]);

    $this->package = 'acme/licensed-widget';
    $this->target = $this->pluginsRoot.'/acme/licensed-widget';

    $this->zipPath = tempnam(sys_get_temp_dir(), 'magna-licensed-swap-').'.zip';

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
    (new Filesystem)->remove([$this->zipPath, $this->pluginsRoot]);
});

function swapGrant(string $sha, string $secret): array
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

it('swaps an update in and leaves the vendor directory clean', function (): void {
    Http::fake(['*' => Http::response($this->zipBytes)]);

    $files = new Filesystem;
    $files->mkdir($this->target);
    file_put_contents($this->target.'/magna.json', json_encode([
        'name' => $this->package,
        'version' => '1.0.0',
    ]));

    PluginRecord::query()->create([
        'name' => $this->package,
        'display_name' => 'Licensed Widget',
        'version' => '1.0.0',
        'base_path' => $this->target,
        'manifest' => ['name' => $this->package, 'version' => '1.0.0'],
        'enabled' => true,
        'requires_license' => true,
    ]);

    try {
        app(LicenseInstaller::class)
            ->installFromGrant($this->package, swapGrant($this->zipSha, $this->signingSecret));
    } catch (Throwable $e) {
        // enable() instantiates the entry class, which this fixture does not
        // ship. The swap on disk is the thing under test.
        expect($e->getMessage())->not->toContain('was not found');
    }

    // The new version is in place…
    $manifest = json_decode((string) file_get_contents($this->target.'/magna.json'), true);
    expect($manifest['version'] ?? null)->toBe('2.0.0');

    // …and the vendor directory holds ONLY the package: no displaced copy,
    // no staging debris, nothing a plugins-dev/*/* glob could mistake for a
    // second package definition.
    $entries = array_values(array_diff(scandir($this->pluginsRoot.'/acme') ?: [], ['.', '..']));
    expect($entries)->toBe(['licensed-widget']);
});
