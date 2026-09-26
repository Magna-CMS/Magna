<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3).'/bin/support/release-manifest.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\ComposerResult;
use Magna\Marketplace\ComposerRunner;
use Magna\Updater\CoreUpdater;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\Manifest\ReleaseManifest;
use Magna\Updater\Run\RunOutcome;
use Magna\Updater\Run\RunRollback;
use Magna\Updater\Run\RunState;
use Magna\Updater\Run\UpdateJournal;
use Magna\Updater\Run\UpdateResumer;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Tier A, end to end: the release's own engine stages and switches the
 * paths its manifest names, the old side steps back, and the new code
 * finishes — or the whole thing is renamed back.
 */
const HANDOFF_PATHS = ['config/defaults', 'src/Magna', 'app', 'bootstrap', 'routes', 'database/migrations', 'public/build', 'public/fonts'];

function handOffInstall(): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-handoff-'.bin2hex(random_bytes(6));

    foreach (HANDOFF_PATHS as $relative) {
        mkdir($base.'/'.$relative, 0777, true);
        file_put_contents($base.'/'.$relative.'/Installed.php', "<?php // installed\n");
    }
    mkdir($base.'/storage/app', 0777, true);
    mkdir($base.'/vendor/composer', 0777, true);
    file_put_contents($base.'/vendor/composer/autoload_classmap.php', '<?php return [];');

    $paths = new UpdatePaths($base, $base.'/storage');
    app()->instance(UpdatePaths::class, $paths);
    config(['magna.updater.allow_unsigned_checksum' => true]);

    return $paths;
}

function removeHandOffInstall(UpdatePaths $paths): void
{
    // Never leave the real application down because a fixture failed.
    Artisan::call('up');
    (new Filesystem)->remove($paths->basePath);
}

/**
 * A release archive with a manifest AND an engine — the shipped one unless a
 * test supplies its own — so the installed updater hands off to it.
 *
 * @return array{bytes: string, sha256: string}
 */
function handOffArchive(string $version, ?string $engineSource = null, array $manifestOverrides = [], array $extraEntries = []): array
{
    $engineSource ??= (string) file_get_contents(base_path('bootstrap/update/engine.php'));

    $manifest = release_manifest($version, HANDOFF_PATHS, ['Magna\\Admin\\Pages\\GeneralSettingsPage'], 'v1.2.0', 4096, ['commit' => null, 'tag' => null], gmdate('c'));
    $manifest['checks']['files'] = ['src/Magna/Delivered.php'];
    $manifest['checks']['classes'] = ['Magna\\Updater\\CoreUpdater'];
    $manifest['paths']['optional'] = [];
    $manifest['engine'] = ['api' => 1, 'path' => 'bootstrap/update/engine.php', 'sha256' => hash('sha256', $engineSource)];

    foreach ($manifestOverrides as $key => $value) {
        $manifest[$key] = is_array($value) && isset($manifest[$key]) && is_array($manifest[$key]) && ! array_is_list($value)
            ? array_replace($manifest[$key], $value)
            : $value;
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'magna-handoff-zip-');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    foreach (HANDOFF_PATHS as $relative) {
        $zip->addFromString($relative.'/Delivered.php', "<?php // delivered by the release\n");
    }
    $zip->addFromString('bootstrap/update/engine.php', $engineSource);
    $zip->addFromString(ReleaseManifest::FILENAME, (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    foreach ($extraEntries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    $bytes = (string) file_get_contents($zipPath);
    @unlink($zipPath);

    return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes)];
}

it('switches a release in with renames, hands off to the new code, and can be rolled back', function (): void {
    $paths = handOffInstall();
    $archive = handOffArchive('99.0.0');
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Switched)
            ->and(CoreUpdater::progress()['state'])->toBe('switched');

        // The release is live, the previous content sits beside it, nothing was mirrored.
        expect(is_file($paths->base('src/Magna/Delivered.php')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeFalse();
        $displaced = glob($paths->base('src/.Magna.replaced-*')) ?: [];
        expect($displaced)->toHaveCount(1)
            ->and(is_file($displaced[0].'/Installed.php'))->toBeTrue();

        // The journal says so, the boot guard is armed, workers were told to go, and the site is down.
        $journal = UpdateJournal::latestPending($paths);
        expect($journal)->not->toBeNull()
            ->and($journal->state())->toBe(RunState::FinalizePending)
            ->and($journal->string('to'))->toBe('99.0.0')
            ->and($journal->paths()['src/Magna']['state'] ?? null)->toBe('swapped');
        expect(is_file(RunRollback::bootGuardMarker($paths)))->toBeTrue()
            ->and(Cache::get('illuminate:queue:restart'))->not->toBeNull()
            ->and(app()->maintenanceMode()->active())->toBeTrue()
            ->and(is_file($paths->draftFootprint()))->toBeTrue();

        // This process runs the old release: it must not finish the update.
        $resumer = app(UpdateResumer::class);
        expect($resumer->resume()->kind)->toBe(RunOutcome::STALE_CODE);

        // Rolling back is the same renames the other way, and the site comes up.
        $outcome = $resumer->rollback();

        expect($outcome->kind)->toBe(RunOutcome::ROLLED_BACK)
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Delivered.php')))->toBeFalse()
            ->and(glob($paths->base('src/.Magna.replaced-*')) ?: [])->toBe([])
            ->and(is_file(RunRollback::bootGuardMarker($paths)))->toBeFalse()
            ->and(app()->maintenanceMode()->active())->toBeFalse()
            ->and(UpdateJournal::latestPending($paths))->toBeNull()
            ->and(CoreUpdater::progress()['state'])->toBe('failed');
    } finally {
        removeHandOffInstall($paths);
    }
});

it('finishes a repair under the same code and records the delivery', function (): void {
    $paths = handOffInstall();
    $archive = handOffArchive(MagnaServiceProvider::VERSION);
    $zipPath = $paths->storage('app/repair.zip');
    file_put_contents($zipPath, $archive['bytes']);

    // An updated site's map still names the page a release retired.
    file_put_contents($paths->base('vendor/composer/autoload_classmap.php'), "<?php return ['Magna\\\\Admin\\\\Pages\\\\GeneralSettingsPage' => '/gone/GeneralSettingsPage.php'];");

    try {
        $state = app(CoreUpdater::class)->repair($zipPath, $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Switched)
            ->and(is_file($zipPath))->toBeTrue();

        // Same version on both sides of the switch, so this process is "the new code".
        $outcome = app(UpdateResumer::class)->resume();

        expect($outcome->kind)->toBe(RunOutcome::COMPLETED)
            ->and($outcome->message)->toBe('Repaired v'.MagnaServiceProvider::VERSION.'.');

        expect(is_file($paths->base('src/Magna/Delivered.php')))->toBeTrue()
            ->and(glob($paths->base('src/.Magna.*')) ?: [])->toBe([])
            ->and(is_dir($paths->base('bootstrap/cache')))->toBeTrue()
            ->and(is_file(RunRollback::bootGuardMarker($paths)))->toBeFalse()
            ->and(app()->maintenanceMode()->active())->toBeFalse()
            ->and(UpdateJournal::latestPending($paths))->toBeNull()
            ->and(CoreUpdater::progress()['state'])->toBe('completed');

        $recorded = (new InstalledFootprint($paths))->read();
        expect($recorded['installed_via'] ?? null)->toBe('repair')
            ->and($recorded['version'] ?? null)->toBe(MagnaServiceProvider::VERSION)
            ->and($recorded['paths'] ?? [])->toContain('src/Magna');

        // The finalizer told the next boot which classmap entries the release retired.
        $cache = json_decode((string) file_get_contents($paths->storage('framework/cache/magna-stale-classmap.json')), true);
        expect($cache['stale'] ?? null)->toBe(['Magna\\Admin\\Pages\\GeneralSettingsPage']);
    } finally {
        removeHandOffInstall($paths);
    }
});

it('rolls back and reports when the engine fails after the switch began', function (): void {
    $paths = handOffInstall();
    $engine = "<?php\nreturn static function (object \$ctx): void {\n    \$ctx->stage('src/Magna');\n    \$ctx->beginSwitch();\n    \$ctx->swap('src/Magna');\n    throw new \\RuntimeException('engine exploded');\n};\n";
    $archive = handOffArchive('99.0.0', $engine);
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('previous release was put back')
            ->and(CoreUpdater::progress()['message'])->toContain('engine exploded')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Delivered.php')))->toBeFalse()
            ->and(app()->maintenanceMode()->active())->toBeFalse()
            ->and(is_file(RunRollback::bootGuardMarker($paths)))->toBeFalse()
            ->and(UpdateJournal::latestPending($paths))->toBeNull();
    } finally {
        removeHandOffInstall($paths);
    }
});

it('refuses an engine whose hash is not the one the manifest states', function (): void {
    $paths = handOffInstall();
    $archive = handOffArchive('99.0.0', null, ['engine' => ['sha256' => str_repeat('a', 64)]]);
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('does not match the hash')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(glob($paths->base('src/.Magna.*')) ?: [])->toBe([]);
    } finally {
        removeHandOffInstall($paths);
    }
});

/*
|--------------------------------------------------------------------------
| Vendor: what the release asks, what the site allows
|--------------------------------------------------------------------------
|
| A release archive carries the complete vendor/ it was built with. Every
| update before this left the site's vendor/ alone forever, so an updated
| site ran new code on old dependencies and kept every classmap entry for a
| class core had deleted. Now the release asks for vendor/ to be replaced,
| and the site decides what that costs.
*/

/** @return array<string, string> archive entries for a vendor tree built with $packages */
function engineVendorEntries(array $packages, array $require): array
{
    $installed = [];
    foreach ($packages as $name => $version) {
        $installed[] = ['name' => $name, 'version' => $version];
    }

    return [
        'composer.json' => (string) json_encode(['require' => $require]),
        'composer.lock' => (string) json_encode(['packages' => $installed]),
        'vendor/autoload.php' => "<?php // release autoloader\n",
        'vendor/composer/installed.json' => (string) json_encode(['packages' => $installed, 'dev' => false]),
    ];
}

/** Give the fixture install a vendor tree of its own. */
function installSiteVendor(UpdatePaths $paths, array $packages, array $require, array $repositories = []): void
{
    $installed = [];
    foreach ($packages as $name => $version) {
        $installed[] = ['name' => $name, 'version' => $version];
    }

    $composer = ['require' => $require];
    if ($repositories !== []) {
        $composer['repositories'] = $repositories;
    }

    file_put_contents($paths->base('composer.json'), (string) json_encode($composer));
    file_put_contents($paths->base('composer.lock'), (string) json_encode(['packages' => $installed]));
    file_put_contents($paths->base('vendor/autoload.php'), "<?php // site autoloader\n");
    file_put_contents($paths->base('vendor/composer/installed.json'), (string) json_encode(['packages' => $installed, 'dev' => false]));
}

/** A Composer that answers as told, and remembers what it was asked. */
function fakeComposer(bool $available, int $exitCode = 0): ComposerRunner
{
    return new class($available, $exitCode) implements ComposerRunner
    {
        /** @var list<list<string>> */
        public array $runs = [];

        public function __construct(private readonly bool $available, private readonly int $exitCode) {}

        public function isAvailable(): bool
        {
            return $this->available;
        }

        public function run(array $args, int $timeout = 300): ComposerResult
        {
            $this->runs[] = $args;

            return new ComposerResult($this->exitCode, $this->exitCode === 0 ? 'ok' : 'Your requirements could not be resolved to an installable set of packages.');
        }
    };
}

it('replaces vendor whole when nothing in it is the site\'s own', function (): void {
    $paths = handOffInstall();
    installSiteVendor($paths, ['laravel/framework' => 'v13.23.0'], ['laravel/framework' => '^13']);
    $archive = handOffArchive('99.0.0', null, ['vendor' => ['strategy' => 'replace']], engineVendorEntries(['laravel/framework' => 'v13.30.0'], ['laravel/framework' => '^13']));
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Switched)
            ->and((string) file_get_contents($paths->base('vendor/autoload.php')))->toContain('release autoloader');

        $journal = UpdateJournal::latestPending($paths);
        expect($journal)->not->toBeNull()
            ->and($journal->paths()['vendor']['state'] ?? null)->toBe('swapped')
            ->and($journal->paths()['composer.lock']['state'] ?? null)->toBe('swapped')
            ->and($journal->get('vendor')['decision'] ?? null)->toBe('replace')
            ->and(is_file($paths->base('.vendor.replaced-'.$journal->runId().'/autoload.php')))->toBeTrue();

        $draft = json_decode((string) file_get_contents($paths->draftFootprint()), true);
        expect($draft['vendor']['decision'] ?? null)->toBe('replace')
            ->and($draft['paths'] ?? [])->toContain('vendor');

        // Rolling back puts the site's own vendor back too.
        app(UpdateResumer::class)->rollback();

        expect((string) file_get_contents($paths->base('vendor/autoload.php')))->toContain('site autoloader');
    } finally {
        removeHandOffInstall($paths);
    }
});

it('leaves vendor alone when the site already holds what the release was built with', function (): void {
    $paths = handOffInstall();
    installSiteVendor($paths, ['laravel/framework' => 'v13.30.0'], ['laravel/framework' => '^13']);
    $archive = handOffArchive('99.0.0', null, ['vendor' => ['strategy' => 'replace']], engineVendorEntries(['laravel/framework' => 'v13.30.0'], ['laravel/framework' => '^13']));
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Switched)
            ->and((string) file_get_contents($paths->base('vendor/autoload.php')))->toContain('site autoloader')
            ->and(UpdateJournal::latestPending($paths)?->get('vendor')['decision'] ?? null)->toBe('keep');
    } finally {
        removeHandOffInstall($paths);
    }
});

/*
 * Archives built before 1.3.25 shipped the build machine's absolute SDK path
 * as a repository, so every site installed from one names a repository the
 * release does not. It served nothing of the site's own, and it leaves with
 * the site's composer.json rather than blocking the update.
 */
it('replaces vendor whole when the only repository the site names served nothing of its own', function (): void {
    $paths = handOffInstall();
    installSiteVendor($paths, ['laravel/framework' => 'v13.23.0'], ['laravel/framework' => '^13'], [['type' => 'path', 'url' => 'C:/Users/builder/magna-plugin-sdk', 'options' => ['symlink' => false]]]);
    $archive = handOffArchive('99.0.0', null, ['vendor' => ['strategy' => 'replace']], engineVendorEntries(['laravel/framework' => 'v13.30.0'], ['laravel/framework' => '^13']));
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Switched)
            ->and((string) file_get_contents($paths->base('vendor/autoload.php')))->toContain('release autoloader')
            ->and(UpdateJournal::latestPending($paths)?->get('vendor')['decision'] ?? null)->toBe('replace')
            ->and(json_decode((string) file_get_contents($paths->base('composer.json')), true)['repositories'] ?? null)->toBeNull();
    } finally {
        removeHandOffInstall($paths);
    }
});

it('refuses before staging when the site\'s own packages come through a repository the release cannot carry', function (): void {
    $paths = handOffInstall();
    installSiteVendor($paths, ['laravel/framework' => 'v13.23.0', 'acme/forum' => 'v2.1.4'], ['laravel/framework' => '^13', 'acme/forum' => '^2.1'], [['type' => 'path', 'url' => 'plugins-dev/acme/forum']]);
    mkdir($paths->base('plugins-dev/acme/forum'), 0777, true);
    $archive = handOffArchive('99.0.0', null, ['vendor' => ['strategy' => 'replace']], engineVendorEntries(['laravel/framework' => 'v13.30.0'], ['laravel/framework' => '^13']));
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('before any files were changed')
            ->and(CoreUpdater::progress()['message'])->toContain('acme/forum')
            ->and(CoreUpdater::progress()['message'])->toContain('path:plugins-dev/acme/forum')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(glob($paths->base('src/.Magna.incoming-*')) ?: [])->toBe([])
            ->and(app()->maintenanceMode()->active())->toBeFalse();
    } finally {
        removeHandOffInstall($paths);
    }
});

it('puts the site\'s own packages back with Composer after replacing vendor', function (): void {
    $paths = handOffInstall();
    installSiteVendor($paths, ['laravel/framework' => 'v13.23.0', 'acme/forum' => 'v2.1.4'], ['laravel/framework' => '^13', 'acme/forum' => '^2.1']);
    $archive = handOffArchive('99.0.0', null, ['vendor' => ['strategy' => 'replace']], engineVendorEntries(['laravel/framework' => 'v13.30.0'], ['laravel/framework' => '^13']));
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    $composer = fakeComposer(available: true);
    app()->instance(ComposerRunner::class, $composer);

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Switched)
            ->and($composer->runs)->toHaveCount(1)
            ->and($composer->runs[0][0])->toBe('require')
            ->and($composer->runs[0])->toContain('acme/forum:v2.1.4')
            ->and(UpdateJournal::latestPending($paths)?->get('vendor')['decision'] ?? null)->toBe('composer');
    } finally {
        removeHandOffInstall($paths);
    }
});

it('refuses when the site has packages of its own and no Composer to put them back', function (): void {
    $paths = handOffInstall();
    installSiteVendor($paths, ['laravel/framework' => 'v13.23.0', 'acme/forum' => 'v2.1.4'], ['laravel/framework' => '^13', 'acme/forum' => '^2.1']);
    $archive = handOffArchive('99.0.0', null, ['vendor' => ['strategy' => 'replace']], engineVendorEntries(['laravel/framework' => 'v13.30.0'], ['laravel/framework' => '^13']));
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    app()->instance(ComposerRunner::class, fakeComposer(available: false));

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('acme/forum')
            ->and(CoreUpdater::progress()['message'])->toContain('Composer is not available')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue();
    } finally {
        removeHandOffInstall($paths);
    }
});

it('rolls the whole switch back when Composer cannot put the site\'s packages back', function (): void {
    $paths = handOffInstall();
    installSiteVendor($paths, ['laravel/framework' => 'v13.23.0', 'acme/forum' => 'v2.1.4'], ['laravel/framework' => '^13', 'acme/forum' => '^2.1']);
    $archive = handOffArchive('99.0.0', null, ['vendor' => ['strategy' => 'replace']], engineVendorEntries(['laravel/framework' => 'v13.30.0'], ['laravel/framework' => '^13']));
    Http::fake(['github.com/*' => Http::response($archive['bytes'])]);

    app()->instance(ComposerRunner::class, fakeComposer(available: true, exitCode: 2));

    try {
        $state = app(CoreUpdater::class)->apply('99.0.0', 'https://github.com/magna-cms/magna/archive/v99.0.0.zip', $archive['sha256']);

        expect($state)->toBe(CoreUpdateState::Failed)
            ->and(CoreUpdater::progress()['message'])->toContain('previous release was put back')
            ->and(CoreUpdater::progress()['message'])->toContain('composer require failed')
            ->and((string) file_get_contents($paths->base('vendor/autoload.php')))->toContain('site autoloader')
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(app()->maintenanceMode()->active())->toBeFalse();
    } finally {
        removeHandOffInstall($paths);
    }
});
