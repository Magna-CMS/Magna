<?php

declare(strict_types=1);

use Magna\Plugins\PluginDiscovery;
use Tests\TestCase;

uses(TestCase::class);

// S1-19 regression: plugins-dev/*/*/magna.json was previously globbed
// unconditionally, regardless of Composer wiring — so an example plugin
// like "seo" (has a manifest, but isn't in root composer.json's
// require/require-dev) was still discoverable/installable, undermining the
// assumption that Composer wiring is the actual trust boundary.

it('does not discover a dev plugin that is not wired into root composer.json', function (): void {
    $discovery = new PluginDiscovery(base_path());

    $names = array_map(fn ($info) => $info->manifest->name, $discovery->discover());

    expect($names)->not->toContain('magna/seo');
});

it('still discovers dev plugins that are wired into root composer.json', function (): void {
    skipWithoutDevPlugin('magna-cms/docs');

    $discovery = new PluginDiscovery(base_path());

    $names = array_map(fn ($info) => $info->manifest->name, $discovery->discover());

    expect($names)->toContain('magna-cms/docs');
});

it('never discovers plugins-dev plugins in production, even if wired', function (): void {
    // Isolated fixture (not this project's real base_path()) so this test
    // isn't confounded by the fact that every plugin wired here also
    // happens to be materialized into vendor/ (a legitimate, environment-
    // independent Composer path-repo install) — the production gate only
    // needs to stop the plugins-dev/ *source* itself, not a genuinely
    // Composer-installed package that happens to originate from a path repo.
    $fixture = sys_get_temp_dir().'/magna_discovery_test_'.uniqid();
    $pluginDir = $fixture.'/plugins-dev/acme/widget';
    mkdir($pluginDir, 0777, true);
    file_put_contents($fixture.'/composer.json', json_encode([
        'repositories' => [['type' => 'path', 'url' => 'plugins-dev/acme/widget']],
    ]));
    file_put_contents($pluginDir.'/magna.json', json_encode([
        'name' => 'acme/widget', 'displayName' => 'Widget', 'description' => 'x',
        'version' => '1.0.0', 'author' => 'Acme', 'license' => 'MIT',
        'compat' => ['magna' => '^1.0'], 'entry' => 'Acme\\Widget\\WidgetPlugin', 'permissions' => [],
    ]));

    try {
        $discovery = new PluginDiscovery($fixture);
        expect(array_map(fn ($i) => $i->manifest->name, $discovery->discover()))->toContain('acme/widget');

        app()->detectEnvironment(fn () => 'production');
        $discovery->reset(); // new "request": env changed, so re-scan (prod caches per-request too)
        $namesInProduction = array_map(fn ($i) => $i->manifest->name, $discovery->discover());
        app()->detectEnvironment(fn () => 'testing');

        expect($namesInProduction)->not->toContain('acme/widget');
    } finally {
        unlink($pluginDir.'/magna.json');
        unlink($fixture.'/composer.json');
        rmdir($pluginDir);
        rmdir($fixture.'/plugins-dev/acme');
        rmdir($fixture.'/plugins-dev');
        rmdir($fixture);
    }
});
