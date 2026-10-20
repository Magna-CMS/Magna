<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Magna\Admin\AdminAssetPublisher;
use Magna\Marketplace\ComposerResult;
use Magna\Marketplace\ComposerRunner;
use Magna\Marketplace\InstallState;
use Magna\Marketplace\Marketplace;
use Magna\Marketplace\PluginInstaller;
use Magna\Plugins\PluginDiscovery;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Tests\Support\FakeComposerRunner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function fakeRunner(): FakeComposerRunner
{
    $runner = new FakeComposerRunner;
    app()->instance(ComposerRunner::class, $runner);

    return $runner;
}

/** @param list<array<string, mixed>> $catalog */
function fakeMarket(array $catalog): void
{
    Http::fake([Marketplace::API_BASE.'/*' => Http::response($catalog)]);
}

beforeEach(function (): void {
    Cache::flush();
});

it('installs and enables an approved plugin', function (): void {
    // enable() resolves the real discovered plugin, so this one needs it present.
    skipWithoutDevPlugin('magna-cms/docs');

    // Read the version off the plugin rather than pinning a literal:
    // verifyInstalledManifest() compares the approved version against the
    // manifest actually on disk, so a hardcoded one fails the install the day
    // the plugin ships a release.
    $version = devPluginVersion('magna-cms/docs');

    fakeMarket([['package' => 'magna-cms/docs', 'name' => 'Magna Docs', 'version' => $version, 'compat' => '^1.0']]);
    $runner = fakeRunner();

    $state = app(PluginInstaller::class)->install('magna-cms/docs');

    expect($state)->toBe(InstallState::Completed)
        // Stage 6: pinned to the exact approved version, not an unpinned "latest".
        ->and($runner->commands)->toContain(['require', 'magna-cms/docs:'.$version])
        ->and(PluginRecord::query()->where('name', 'magna-cms/docs')->where('enabled', true)->exists())->toBeTrue();
});

it('tolerates the catalog listing a Composer tag with a leading v', function (): void {
    // Composer tags releases vX.Y.Z and the catalog stores the tag, while
    // manifests carry the bare number. v1.1.0 vs 1.1.0 rolled back every
    // install of the docs plugin in production — pin the normalization.
    skipWithoutDevPlugin('magna-cms/docs');

    $version = devPluginVersion('magna-cms/docs');

    fakeMarket([['package' => 'magna-cms/docs', 'name' => 'Magna Docs', 'version' => 'v'.$version, 'compat' => '^1.0']]);
    fakeRunner();

    expect(app(PluginInstaller::class)->install('magna-cms/docs'))->toBe(InstallState::Completed);
});

it('refuses a package that is not in the marketplace', function (): void {
    fakeMarket([]); // empty catalog + no detail
    Http::fake([Marketplace::API_BASE.'/*' => Http::response('', 404)]);
    $runner = fakeRunner();

    $state = app(PluginInstaller::class)->install('evil/backdoor');

    expect($state)->toBe(InstallState::Failed)
        ->and($runner->commands)->toBe([]); // Composer never ran
});

it('fails cleanly when composer cannot install', function (): void {
    fakeMarket([['package' => 'acme/thing', 'name' => 'Thing', 'version' => '1.0.0', 'compat' => '^1.0']]);
    $runner = fakeRunner();
    $runner->exitCode = 1;
    $runner->output = 'Could not resolve dependencies.';

    $state = app(PluginInstaller::class)->install('acme/thing');

    expect($state)->toBe(InstallState::Failed)
        ->and(PluginInstaller::progress('acme/thing')['message'])->toContain('Composer');
});

it('rolls back when the plugin installs but fails to enable', function (): void {
    // acme/ghost is "approved" and composer "succeeds", but it isn't really
    // installed, so PluginManager::enable() throws → rollback.
    fakeMarket([['package' => 'acme/ghost', 'name' => 'Ghost', 'version' => '1.0.0', 'compat' => '^1.0']]);
    $runner = fakeRunner();

    $state = app(PluginInstaller::class)->install('acme/ghost');

    expect($state)->toBe(InstallState::Failed)
        ->and($runner->commands)->toContain(['require', 'acme/ghost:1.0.0'])
        ->and($runner->commands)->toContain(['remove', 'acme/ghost']); // rolled back
});

it('rolls back when the installed manifest version does not match the approved version', function (): void {
    // magna/docs is a real, discoverable dev plugin whose manifest version
    // is 1.0.0 — claim the marketplace approved a different version to
    // simulate Composer resolving something other than what was reviewed.
    // The "found manifest" wording below only exists when discovery really
    // finds the plugin, so a checkout without plugins-dev skips.
    skipWithoutDevPlugin('magna-cms/docs');

    fakeMarket([['package' => 'magna-cms/docs', 'name' => 'Magna Docs', 'version' => '9.9.9', 'compat' => '^1.0']]);
    $runner = fakeRunner();

    $state = app(PluginInstaller::class)->install('magna-cms/docs');

    expect($state)->toBe(InstallState::Failed)
        ->and($runner->commands)->toContain(['require', 'magna-cms/docs:9.9.9'])
        ->and($runner->commands)->toContain(['remove', 'magna-cms/docs'])
        ->and(PluginInstaller::progress('magna-cms/docs')['message'])->toContain('did not match the approved listing')
        // The message must name what was found — a bare "did not match"
        // already cost one debugging session too many.
        ->and(PluginInstaller::progress('magna-cms/docs')['message'])->toContain('found manifest magna-cms/docs');
});

it('fails when composer is unavailable on the host', function (): void {
    fakeMarket([['package' => 'acme/thing', 'name' => 'Thing', 'version' => '1.0.0', 'compat' => '^1.0']]);
    $runner = fakeRunner();
    $runner->available = false;

    $state = app(PluginInstaller::class)->install('acme/thing');

    expect($state)->toBe(InstallState::Failed)
        ->and($runner->commands)->toBe([]);
});

// magna-cms/docs was approved at v1.1.0, the tag was later replaced by v1.1.1,
// and the panel then showed nothing but Composer's resolver output — accurate,
// but unreadable as "the listing is stale, not the installer".
it('names a stale listing when the approved version is no longer published', function (): void {
    fakeMarket([['package' => 'acme/forum', 'name' => 'Acme Forum', 'version' => 'v1.1.0', 'compat' => '^1.0']]);

    $runner = fakeRunner();
    $runner->exitCode = 1;
    $runner->output = 'Problem 1'."\n"
        .'  - Root composer.json requires acme/forum v1.1.0 (exact version match), found acme/forum[dev-main, v1.1.1, 1.x-dev (alias of dev-main)] but it does not match the constraint.';

    expect(app(PluginInstaller::class)->install('acme/forum'))->toBe(InstallState::Failed);

    $message = (string) (Cache::get('magna.marketplace.install.acme/forum')['message'] ?? '');

    expect($message)->toContain('The marketplace lists v1.1.0, which acme/forum no longer publishes.')
        ->and($message)->toContain('dev-main, v1.1.1')
        // Composer's own output still follows, for whoever needs the detail.
        ->and($message)->toContain('exact version match');
});

// ── Updating ──────────────────────────────────────────────────────────────────
//
// The free-plugin update button used to call PluginManager::enable(), which
// re-reads what is already on disk. For a plugin delivered by Composer the new
// release was still on Packagist, so the panel reported "Plugin updated." and
// changed nothing — the Lekha site sat two releases behind for a week that way.

it('updates by installing the version the marketplace now advertises', function (): void {
    skipWithoutDevPlugin('magna-cms/docs');

    $version = devPluginVersion('magna-cms/docs');

    fakeMarket([['package' => 'magna-cms/docs', 'name' => 'Magna Docs', 'version' => $version, 'compat' => '^1.0']]);
    $runner = fakeRunner();

    $state = app(PluginInstaller::class)->update('magna-cms/docs');

    expect($state)->toBe(InstallState::Completed)
        ->and($runner->commands)->toContain(['require', 'magna-cms/docs:'.$version])
        ->and(PluginRecord::query()->where('name', 'magna-cms/docs')->where('enabled', true)->exists())->toBeTrue();
});

// The one difference that matters. `composer remove` is the right rollback for
// an install — the package was not there before — and catastrophic for an
// update: it would uninstall a plugin that was serving the site a minute ago.
it('rolls a failed update back to the version that was installed, never removing it', function (): void {
    skipWithoutDevPlugin('magna-cms/docs');

    $installed = devPluginVersion('magna-cms/docs');

    // Approved at a version that is not what the manifest on disk says, so the
    // post-install manifest check fails and the rollback runs.
    fakeMarket([['package' => 'magna-cms/docs', 'name' => 'Magna Docs', 'version' => '9.9.9', 'compat' => '^1.0']]);
    $runner = fakeRunner();

    $state = app(PluginInstaller::class)->update('magna-cms/docs');

    expect($state)->toBe(InstallState::Failed)
        ->and($runner->commands)->toContain(['require', 'magna-cms/docs:9.9.9'])
        ->and($runner->commands)->toContain(['require', 'magna-cms/docs:'.$installed])
        ->and($runner->commands)->not->toContain(['remove', 'magna-cms/docs']);
});

// A plugin that is not installed has no previous version to pin back, so the
// only sane undo is the install one. Guards against rolling back to "null".
it('removes rather than restores when an update finds nothing installed', function (): void {
    fakeMarket([['package' => 'acme/ghost', 'name' => 'Ghost', 'version' => '1.0.0', 'compat' => '^1.0']]);
    $runner = fakeRunner();

    $state = app(PluginInstaller::class)->update('acme/ghost');

    expect($state)->toBe(InstallState::Failed)
        ->and($runner->commands)->toContain(['remove', 'acme/ghost']);
});

// An update is a site keeping itself current, not a new adoption: counting it
// would inflate the marketplace's install numbers on every release.
it('does not report an update as a fresh install', function (): void {
    skipWithoutDevPlugin('magna-cms/docs');

    $version = devPluginVersion('magna-cms/docs');

    fakeMarket([['package' => 'magna-cms/docs', 'name' => 'Magna Docs', 'version' => $version, 'compat' => '^1.0']]);
    fakeRunner();

    app(PluginInstaller::class)->update('magna-cms/docs');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/installed'));
});

// Every update of a Composer-delivered plugin rolled itself back on a real
// site. The update reads the installed version BEFORE Composer runs (it is
// what a failure rolls back to), and PluginDiscovery memoized that scan — so
// the post-install manifest check read the release Composer had just
// replaced: "expected v1.1.0, found manifest 1.0.0". The tests above never
// saw it because their fake Composer changes nothing on disk. This one swaps
// the manifest the way a real `composer require` does.
it('checks the manifest Composer just installed on an update, not the one it replaced', function (): void {
    $fixture = sys_get_temp_dir().'/magna_update_scan_'.uniqid();
    $pkgDir = $fixture.'/vendor/acme/widget';
    mkdir($fixture.'/vendor/composer', 0777, true);
    mkdir($pkgDir, 0777, true);
    file_put_contents($fixture.'/vendor/composer/installed.json', json_encode([
        'packages' => [['name' => 'acme/widget', 'type' => 'magna-plugin']],
    ]));
    $manifest = fn (string $version): string => (string) json_encode([
        'name' => 'acme/widget', 'displayName' => 'Widget', 'description' => 'x',
        'version' => $version, 'author' => 'Acme', 'license' => 'MIT',
        'compat' => ['magna' => '^1.0'], 'entry' => 'Acme\Widget\WidgetPlugin', 'permissions' => [],
    ]);
    file_put_contents($pkgDir.'/magna.json', $manifest('1.0.0'));

    // The installer and the manager it enables through must share the
    // fixture's scan, exactly as they share the real singleton.
    app()->instance(PluginDiscovery::class, new PluginDiscovery($fixture));
    app()->forgetInstance(PluginManager::class);

    $runner = new class extends FakeComposerRunner
    {
        /** @var (Closure(list<string>): void)|null */
        public ?Closure $onRun = null;

        public function run(array $args, int $timeout = 300): ComposerResult
        {
            ($this->onRun)($args);

            return parent::run($args, $timeout);
        }
    };
    $runner->onRun = function (array $args) use ($pkgDir, $manifest): void {
        if ($args[0] === 'require' && str_starts_with($args[1], 'acme/widget:')) {
            file_put_contents($pkgDir.'/magna.json', $manifest(ltrim(substr($args[1], strlen('acme/widget:')), 'vV')));
        }
    };
    app()->instance(ComposerRunner::class, $runner);

    fakeMarket([['package' => 'acme/widget', 'name' => 'Widget', 'version' => 'v1.1.0', 'compat' => '^1.0']]);

    try {
        app(PluginInstaller::class)->update('acme/widget');

        // The fixture has no entry class, so enable() fails after the manifest
        // check — which is the point: getting that far proves the check read
        // the swapped 1.1.0 manifest. A stale scan stops one step earlier.
        $message = PluginInstaller::progress('acme/widget')['message'];

        expect($message)->not->toContain('did not match the approved listing')
            ->and($message)->toContain('failed to enable')
            ->and($runner->commands)->toContain(['require', 'acme/widget:v1.1.0'])
            // The rollback still pins the version that was working, read
            // before Composer ran — the scan was only reset AFTER it.
            ->and($runner->commands)->toContain(['require', 'acme/widget:1.0.0']);
    } finally {
        File::deleteDirectory($fixture);
    }
});

// A plugin's admin scripts are only served once `filament:assets` copies them
// out of vendor/, and nothing ran it after an install: on a site whose plugins
// all came from the marketplace, the blog editor's script 404'd, its post
// editor showed a title and no body, and no post could ever be written.
it('publishes Filament assets once a plugin is installed', function (): void {
    skipWithoutDevPlugin('magna-cms/docs');

    $version = devPluginVersion('magna-cms/docs');
    fakeMarket([['package' => 'magna-cms/docs', 'name' => 'Magna Docs', 'version' => $version, 'compat' => '^1.0']]);
    fakeRunner();
    $publisher = new class extends AdminAssetPublisher
    {
        public int $runs = 0;

        public function publish(): void
        {
            $this->runs++;
        }
    };
    app()->instance(AdminAssetPublisher::class, $publisher);

    expect(app(PluginInstaller::class)->install('magna-cms/docs'))->toBe(InstallState::Completed)
        ->and($publisher->runs)->toBe(1);
});

// The plugin is installed and enabled before assets are copied, so a copy
// that fails (a read-only public/, say) is a warning — reporting the whole
// install as failed would invite a retry of something that already worked.
it('does not fail an install because its assets could not be published', function (): void {
    skipWithoutDevPlugin('magna-cms/docs');

    $version = devPluginVersion('magna-cms/docs');
    fakeMarket([['package' => 'magna-cms/docs', 'name' => 'Magna Docs', 'version' => $version, 'compat' => '^1.0']]);
    fakeRunner();
    app()->instance(AdminAssetPublisher::class, new class extends AdminAssetPublisher
    {
        public function publish(): void
        {
            throw new RuntimeException('public/ is read-only');
        }
    });
    Log::spy();

    expect(app(PluginInstaller::class)->install('magna-cms/docs'))->toBe(InstallState::Completed);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'admin assets'))
        ->once();
});
