<?php

declare(strict_types=1);

use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Plugins\PluginDiscovery;
use Magna\Plugins\PluginManager;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// A cached route table (production runs `route:cache`) is built once, from the
// plugins enabled at deploy time — Laravel skips route registration entirely
// while it exists. A plugin enabled afterwards therefore gets NO routes, while
// its nav items and dashboard widgets still render from the plugins table. The
// first one to resolve a page URL throws
//
//     Route [filament.magna.pages.roya-license] not defined.
//
// on every admin request, with no reachable page left to disable it from.
// Enabling or disabling a plugin must drop that stale cache.

/** A wired, discoverable plugin in its own root, so no real checkout is needed. */
function routeCacheFixtureRoot(): string
{
    $root = sys_get_temp_dir().'/magna-route-cache-'.bin2hex(random_bytes(6));
    $plugin = $root.'/plugins-dev/acme/routes';

    mkdir($plugin.'/src', 0777, true);

    file_put_contents($root.'/composer.json', json_encode([
        'require' => ['acme/routes' => '@dev'],
        'repositories' => [['type' => 'path', 'url' => 'plugins-dev/acme/routes']],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($plugin.'/composer.json', json_encode([
        'name' => 'acme/routes',
        'autoload' => ['psr-4' => ['AcmeRouteCache\\' => 'src/']],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($plugin.'/magna.json', json_encode([
        'name' => 'acme/routes',
        'displayName' => 'Route Cache Probe',
        'description' => 'Probe.',
        'version' => '1.0.0',
        'author' => 'Acme',
        'license' => 'proprietary',
        'entry' => 'AcmeRouteCache\\ProbePlugin',
        'compat' => ['magna' => '^1.0', 'php' => '^8.3'],
        'permissions' => [],
        'provides' => [],
    ], JSON_THROW_ON_ERROR));

    file_put_contents(
        $plugin.'/src/ProbePlugin.php',
        "<?php\n\nnamespace AcmeRouteCache;\n\nclass ProbePlugin extends \\Magna\\Plugins\\Plugin {}\n"
    );

    return $root;
}

/** Stand in for a production `route:cache`, and never leave the file behind. */
function withFakeRouteCache(callable $body): void
{
    $app = app();
    expect($app)->toBeInstanceOf(LaravelApplication::class);

    $path = $app->getCachedRoutesPath();

    // Refuse to clobber a cache this checkout actually built.
    expect(file_exists($path))->toBeFalse();

    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, "<?php\n\nreturn [];\n");

    // routesAreCached() is memoized at boot, so a file written afterwards is
    // invisible to it. Bind the flag the way a production boot would have.
    $app->instance('routes.cached', true);

    try {
        expect($app->routesAreCached())->toBeTrue();

        $body($path);
    } finally {
        @unlink($path);
    }
}

beforeEach(function (): void {
    $this->root = routeCacheFixtureRoot();

    app()->instance(PluginDiscovery::class, new PluginDiscovery($this->root));

    // The manager is a singleton that may already hold the real discovery.
    app()->forgetInstance(PluginManager::class);

    $this->manager = app(PluginManager::class);
});

afterEach(function (): void {
    (new Filesystem)->remove($this->root);
});

it('drops the cached route table when a plugin is enabled', function (): void {
    withFakeRouteCache(function (string $path): void {
        $this->manager->enable('acme/routes');

        // Without this the plugin's pages have no route at all, and anything
        // linking to one 500s the whole panel until the next deploy.
        expect(file_exists($path))->toBeFalse();
    });
});

it('drops the cached route table when a plugin is disabled', function (): void {
    $this->manager->enable('acme/routes');

    withFakeRouteCache(function (string $path): void {
        $this->manager->disable('acme/routes');

        // Mirror image of the same bug: a cache still holding routes for a
        // plugin that is no longer booted.
        expect(file_exists($path))->toBeFalse();
    });
});
