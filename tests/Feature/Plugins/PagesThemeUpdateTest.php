<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Magna\Content\EntryManager;
use Magna\Licensing\LicenseInstaller;
use Magna\Licensing\SignedPayload;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\Marketplace;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;
use Symfony\Component\Filesystem\Filesystem;

uses(PluginTestCase::class);

/**
 * The lekha.jrstudios.dev failure end to end: a theme update through the
 * licence installer (the Themes page's Update button), on a site whose pages
 * Magna Pages has cached and whose layout Blade has compiled. Before the fix
 * the next request was a cache HIT with the old layout, and after purging by
 * hand it was a MISS with the old layout still, because the new layout file
 * carried the build machine's mtime. See RenderedOutputRefreshTest for the
 * core-only half.
 */
beforeEach(function (): void {
    $pair = sodium_crypto_sign_keypair();
    $this->signingSecret = sodium_crypto_sign_secretkey($pair);
    config(['magna.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

    $this->themeRoot = storage_path('framework/testing/themes-'.uniqid());
    config(['magna.themes_path' => $this->themeRoot]);
});

afterEach(function (): void {
    (new Filesystem)->remove($this->themeRoot);
});

function pagesThemeUpdateInstall(string $version, string $layout, int $mtime, string $secret): void
{
    $path = tempnam(sys_get_temp_dir(), 'magna-theme-update-').'.zip';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $entries = [
        'theme.json' => (string) json_encode([
            'name' => 'acme/aurora',
            'displayName' => 'Aurora',
            'version' => $version,
            'compat' => ['magna' => MagnaServiceProvider::VERSION],
        ]),
        'views/system/layout.blade.php' => $layout,
    ];
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
        $zip->setMtimeName($name, $mtime);
    }
    $zip->close();

    $bytes = (string) file_get_contents($path);
    @unlink($path);
    $sha = hash('sha256', $bytes);

    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response($bytes)]);

    app(LicenseInstaller::class)->installFromGrant('acme/aurora', [
        'url' => Marketplace::WEB_BASE.'/api/v1/license/download/nonce?signature=x',
        'sha256' => $sha,
        'sha256_signature' => base64_encode(sodium_crypto_sign_detached(
            SignedPayload::canonicalize(['sha256' => $sha]),
            $secret,
        )),
    ]);
}

it('serves the updated theme layout on the next request after a theme update', function (): void {
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    pagesThemeUpdateInstall('1.0.0', '<html><body>LAYOUT ONE {{ $title }}</body></html>', time(), $this->signingSecret);
    app(ThemeManager::class)->activate('acme/aurora');

    $author = User::factory()->create();
    $entries = app(EntryManager::class);
    $entries->publish($entries->create('page', [
        'title' => 'Pricing', 'slug' => 'pricing', 'blocks_data' => [],
    ], $author->id), actorId: $author->id);

    $this->get('/pricing')->assertOk()->assertSee('LAYOUT ONE Pricing')->assertHeader('X-Magna-Cache', 'miss');
    $this->get('/pricing')->assertOk()->assertHeader('X-Magna-Cache', 'hit');

    // Built yesterday, installed now: older than the compiled layout.
    pagesThemeUpdateInstall('1.1.0', '<html><body>LAYOUT TWO {{ $title }}</body></html>', strtotime('2026-01-01 00:00:00'), $this->signingSecret);

    expect(DB::table('pages_cache')->count())->toBe(0);

    // A real next request has a Blade engine that has not yet checked this
    // layout: PHP-FPM starts from nothing, and Octane forgets the engine after
    // every request. One test application keeps Livewire's engine — which has
    // no terminate hook — across requests, so forget it the way Octane does.
    app('view.engine.resolver')->forget('blade');

    $this->get('/pricing')
        ->assertOk()
        ->assertHeader('X-Magna-Cache', 'miss')
        ->assertSee('LAYOUT TWO Pricing')
        ->assertDontSee('LAYOUT ONE');
});
