<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Magna\Frontend\Events\PublicContentChanged;
use Magna\Licensing\LicenseInstaller;
use Magna\Licensing\SignedPayload;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\Marketplace;
use Magna\Plugins\PluginRecord;
use Magna\Themes\ThemeManager;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Regression: a theme update left the site serving the OLD theme.
 *
 * Found on lekha.jrstudios.dev (core 1.6.2) after updating lekha/billing. Two
 * caches answered for the previous version, and nothing told either of them:
 *
 *   1. Blade's compiled view. Extraction keeps each zip entry's mtime — the
 *      build machine's edit time — so the new layout.blade.php was OLDER than
 *      the view compiled from the old one earlier that day. isExpired() asks
 *      "is the source newer than the compiled file", got "no", and the old
 *      layout kept rendering however often the page cache was purged.
 *   2. The public page cache, which held the old HTML outright.
 *
 * The server was fixed by hand with `view:clear` and a PublicContentChanged
 * flush. An update has to do both itself, and so does a plugin update, whose
 * views arrive the same way.
 */
beforeEach(function (): void {
    $pair = sodium_crypto_sign_keypair();
    $this->signingSecret = sodium_crypto_sign_secretkey($pair);
    config(['magna.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

    // Isolated roots, never the repository's real themes/ or plugins-dev/.
    $this->themeRoot = storage_path('framework/testing/themes-'.uniqid());
    $this->pluginsRoot = storage_path('framework/testing/plugins-dev-'.uniqid());
    config(['magna.themes_path' => $this->themeRoot, 'magna.plugins.dev_path' => $this->pluginsRoot]);

    // Well before anything this test compiles: the shape of a package built
    // yesterday and installed today.
    $this->builtAt = strtotime('2026-01-01 00:00:00');
});

afterEach(function (): void {
    (new Filesystem)->remove([$this->themeRoot, $this->pluginsRoot]);
});

/**
 * A package zip whose every entry carries the build machine's mtime.
 *
 * @param  array<string, string>  $entries
 */
function refreshPackageBytes(array $entries, int $mtime): string
{
    $path = tempnam(sys_get_temp_dir(), 'magna-refresh-').'.zip';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
        $zip->setMtimeName($name, $mtime);
    }

    $zip->close();

    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

function refreshInstall(string $slug, string $bytes, string $secret): string
{
    // Fake stubs accumulate and the first match wins, so a second install in
    // one test would download the first package again. Start from a clean
    // factory each time.
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response($bytes)]);

    $sha = hash('sha256', $bytes);

    return app(LicenseInstaller::class)->installFromGrant($slug, [
        'url' => Marketplace::WEB_BASE.'/api/v1/license/download/nonce?signature=x',
        'sha256' => $sha,
        'sha256_signature' => base64_encode(sodium_crypto_sign_detached(
            SignedPayload::canonicalize(['sha256' => $sha]),
            $secret,
        )),
    ]);
}

/** @return array<string, string> */
function refreshThemePackage(string $version, string $layout): array
{
    return [
        'theme.json' => (string) json_encode([
            'name' => 'acme/aurora',
            'displayName' => 'Aurora',
            'version' => $version,
            'compat' => ['magna' => MagnaServiceProvider::VERSION],
        ]),
        'views/system/layout.blade.php' => $layout,
    ];
}

/**
 * What the next request sees. The Blade engine remembers which views it has
 * already checked, and a real next request starts without that list: PHP-FPM
 * from a new process, Octane by forgetting the engine after every request.
 * One test application keeps Livewire's engine across "requests", so forget
 * it the way Octane does.
 */
function refreshRenderAsNextRequest(string $path): string
{
    app('view.engine.resolver')->forget('blade');

    return view()->file($path)->render();
}

it('renders an updated theme layout whose files are older than the compiled view', function (): void {
    $theme = $this->themeRoot.'/acme/aurora';
    $layout = $theme.'/views/system/layout.blade.php';

    // 1.0.0 is on disk and has been rendered: its compiled view exists now.
    refreshInstall('acme/aurora', refreshPackageBytes(refreshThemePackage('1.0.0', 'LAYOUT ONE'), time()), $this->signingSecret);
    expect(refreshRenderAsNextRequest($layout))->toBe('LAYOUT ONE');

    // 1.1.0 arrives carrying yesterday's mtimes.
    refreshInstall('acme/aurora', refreshPackageBytes(refreshThemePackage('1.1.0', 'LAYOUT TWO'), $this->builtAt), $this->signingSecret);

    expect(refreshRenderAsNextRequest($layout))->toBe('LAYOUT TWO');
});

it('tells the public caches that a theme update changed every page', function (): void {
    refreshInstall('acme/aurora', refreshPackageBytes(refreshThemePackage('1.0.0', 'one'), $this->builtAt), $this->signingSecret);

    Event::fake([PublicContentChanged::class]);

    refreshInstall('acme/aurora', refreshPackageBytes(refreshThemePackage('1.1.0', 'two'), $this->builtAt), $this->signingSecret);

    Event::assertDispatched(PublicContentChanged::class, fn (PublicContentChanged $e): bool => $e->everything);
});

it('tells the public caches when a theme is activated or deactivated', function (): void {
    refreshInstall('acme/aurora', refreshPackageBytes(refreshThemePackage('1.0.0', 'one'), $this->builtAt), $this->signingSecret);

    Event::fake([PublicContentChanged::class]);

    $themes = app(ThemeManager::class);

    $themes->activate('acme/aurora');
    Event::assertDispatchedTimes(PublicContentChanged::class, 1);

    $themes->deactivate();
    Event::assertDispatchedTimes(PublicContentChanged::class, 2);
});

it('renders an updated plugin view and purges the public caches on a licensed plugin update', function (): void {
    $package = 'acme/licensed-widget';
    $target = $this->pluginsRoot.'/acme/licensed-widget';
    $view = $target.'/resources/views/card.blade.php';

    $manifest = static fn (string $version): string => (string) json_encode([
        'name' => $package,
        'displayName' => 'Licensed Widget',
        'description' => 'A fixture that exists only inside this test.',
        'version' => $version,
        'author' => 'Acme',
        'license' => 'proprietary',
        'entry' => 'Acme\\LicensedWidget\\WidgetPlugin',
        'compat' => ['magna' => MagnaServiceProvider::VERSION, 'php' => '^8.3'],
        'provides' => [],
        'permissions' => [],
    ]);

    // 1.0.0 on disk, enabled, and its view already compiled.
    (new Filesystem)->dumpFile($target.'/magna.json', $manifest('1.0.0'));
    (new Filesystem)->dumpFile($view, 'CARD ONE');
    PluginRecord::query()->create([
        'name' => $package,
        'display_name' => 'Licensed Widget',
        'version' => '1.0.0',
        'base_path' => $target,
        'manifest' => ['name' => $package, 'version' => '1.0.0'],
        'enabled' => true,
        'requires_license' => true,
    ]);
    expect(refreshRenderAsNextRequest($view))->toBe('CARD ONE');

    Event::fake([PublicContentChanged::class]);

    try {
        refreshInstall($package, refreshPackageBytes([
            'magna.json' => $manifest('2.0.0'),
            'resources/views/card.blade.php' => 'CARD TWO',
        ], $this->builtAt), $this->signingSecret);
    } catch (Throwable $e) {
        // enable() instantiates the entry class, which this fixture does not
        // ship. The files on disk and what renders from them are under test.
        expect($e->getMessage())->not->toContain('was not found')
            ->and($e->getMessage())->not->toContain('magna.json');
    }

    expect(refreshRenderAsNextRequest($view))->toBe('CARD TWO');
    Event::assertDispatched(PublicContentChanged::class, fn (PublicContentChanged $e): bool => $e->everything);
});
