<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Magna\Licensing\LicenseInstaller;
use Magna\Licensing\SignedPayload;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\Marketplace;
use Magna\Themes\InvalidThemeException;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeSettings;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Themes are inert packages: files on disk plus one setting saying which is
 * active. These tests hold that line — nothing about a theme may be able to
 * boot code, and a broken one must never be able to hide the others.
 */
beforeEach(function (): void {
    $this->themeRoot = base_path('themes');
    $this->files = new Filesystem;

    // Tests run against the real themes/ directory, so remember what was
    // there and put it back afterwards rather than assuming it is empty.
    $this->preExisting = is_dir($this->themeRoot) ? (array) glob($this->themeRoot.'/*') : [];
});

afterEach(function (): void {
    foreach ((array) glob($this->themeRoot.'/*') as $path) {
        if (! in_array($path, $this->preExisting, true)) {
            $this->files->remove($path);
        }
    }
});

function writeTheme(string $name, array $overrides = []): string
{
    $files = new Filesystem;
    $dir = base_path('themes/'.$name);
    $files->mkdir($dir, 0755);

    $manifest = array_merge([
        'name' => $name,
        'displayName' => 'Test Theme',
        'description' => 'A theme for tests.',
        'version' => '1.0.0',
        'author' => 'Magna',
        'license' => 'MIT',
        'compat' => ['magna' => '^1.0'],
        'tags' => ['test'],
    ], $overrides);

    file_put_contents($dir.'/theme.json', json_encode($manifest, JSON_PRETTY_PRINT));

    return $dir;
}

it('discovers installed themes and ignores unreadable ones', function (): void {
    writeTheme('acme/clean');

    // Broken JSON: skipped, not fatal — an admin has to be able to see the
    // list in order to remove the thing that is broken.
    $broken = base_path('themes/acme/broken');
    (new Filesystem)->mkdir($broken, 0755);
    file_put_contents($broken.'/theme.json', '{not json');

    $installed = app(ThemeManager::class)->installed();

    expect(array_keys($installed))->toBe(['acme/clean'])
        ->and($installed['acme/clean']->displayName)->toBe('Test Theme');
});

it('refuses a theme sitting in a directory that is not its own', function (): void {
    // themes/acme/clean declaring itself as acme/other — the shape a package
    // would take if it were trying to shadow another vendor's theme.
    writeTheme('acme/clean', ['name' => 'acme/other']);

    expect(app(ThemeManager::class)->installed())->toBe([]);
});

it('activates, deactivates and removes a theme', function (): void {
    writeTheme('acme/clean');

    $themes = app(ThemeManager::class);

    $themes->activate('acme/clean');
    expect(ThemeSettings::get()->active)->toBe('acme/clean')
        ->and($themes->active()?->name)->toBe('acme/clean');

    $themes->deactivate();
    expect(ThemeSettings::get()->active)->toBeNull();

    $themes->activate('acme/clean');
    $themes->remove('acme/clean');

    // Removing the active theme must clear the setting too — otherwise the
    // site points at a directory that no longer exists.
    expect(ThemeSettings::get()->active)->toBeNull()
        ->and(is_dir(base_path('themes/acme/clean')))->toBeFalse();
});

it('refuses to activate a theme built for another core version', function (): void {
    writeTheme('acme/old', ['compat' => ['magna' => '^0.1']]);

    expect(fn () => app(ThemeManager::class)->activate('acme/old'))
        ->toThrow(InvalidThemeException::class, 'not compatible');

    expect(ThemeSettings::get()->active)->toBeNull();
});

it('installs a licensed theme into themes/ and leaves it inactive', function (): void {
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    config(['magna.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

    // A real zip: theme.json at the root of a single wrapping folder, which
    // is what every archive tool produces.
    $zipPath = tempnam(sys_get_temp_dir(), 'theme').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('acme-aurora-1.0.0/theme.json', json_encode([
        'name' => 'acme/aurora',
        'displayName' => 'Aurora',
        'version' => '1.0.0',
        'author' => 'Acme',
        'license' => 'proprietary',
        'compat' => ['magna' => MagnaServiceProvider::VERSION],
    ]));
    $zip->addFromString('acme-aurora-1.0.0/templates/home.html', '<h1>hi</h1>');
    $zip->close();

    $bytes = (string) file_get_contents($zipPath);
    $sha = hash('sha256', $bytes);

    Http::fake(['*' => Http::response($bytes)]);

    $message = app(LicenseInstaller::class)->installFromGrant('acme/aurora', [
        'url' => Marketplace::WEB_BASE.'/api/v1/license/download/nonce?signature=x',
        'sha256' => $sha,
        'sha256_signature' => base64_encode(sodium_crypto_sign_detached(
            SignedPayload::canonicalize(['sha256' => $sha]),
            $secret,
        )),
    ]);

    @unlink($zipPath);

    expect($message)->toContain('Aurora')
        ->and(is_file(base_path('themes/acme/aurora/theme.json')))->toBeTrue()
        // Buying a theme must not silently change what a live site presents.
        ->and(ThemeSettings::get()->active)->toBeNull()
        // And nothing about a theme may end up in the plugin registry.
        ->and(is_dir(base_path('plugins-dev/acme/aurora')))->toBeFalse();
});

it('refuses a theme archive that is not the product the licence covers', function (): void {
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    config(['magna.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

    $zipPath = tempnam(sys_get_temp_dir(), 'theme').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('theme.json', json_encode([
        'name' => 'acme/other', 'displayName' => 'Other', 'version' => '1.0.0',
        'compat' => ['magna' => MagnaServiceProvider::VERSION],
    ]));
    $zip->close();

    $bytes = (string) file_get_contents($zipPath);
    $sha = hash('sha256', $bytes);

    Http::fake(['*' => Http::response($bytes)]);

    expect(fn () => app(LicenseInstaller::class)->installFromGrant('acme/aurora', [
        'url' => Marketplace::WEB_BASE.'/api/v1/license/download/nonce?signature=x',
        'sha256' => $sha,
        'sha256_signature' => base64_encode(sodium_crypto_sign_detached(
            SignedPayload::canonicalize(['sha256' => $sha]),
            $secret,
        )),
    ]))->toThrow(RuntimeException::class, 'not "acme/aurora"');

    @unlink($zipPath);

    expect(is_dir(base_path('themes/acme/other')))->toBeFalse();
});
