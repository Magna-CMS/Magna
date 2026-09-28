<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\MagnaServiceProvider;
use Magna\Plugins\PluginCompatibilityCheck;
use Magna\Plugins\PluginDiscovery;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\Support\ConfigDrift;
use Magna\Support\StaleClassMap;
use Magna\Updater\CoreUpdater;
use Magna\Updater\Footprint\FootprintCheck;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\Run\RunState;
use Magna\Updater\Run\UpdateJournal;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The check that would have caught magna1: 1.4.3 code, no record of what
 * arrived with it, panel saying "up to date".
 */
function footprintCheckPaths(?string $base = null, ?PluginCompatibilityCheck $plugins = null): array
{
    $base ??= sys_get_temp_dir().'/magna-fpcheck-'.bin2hex(random_bytes(6));
    mkdir($base.'/src/Magna', 0777, true);
    mkdir($base.'/storage/app', 0777, true);
    mkdir($base.'/config', 0777, true);

    $paths = new UpdatePaths($base, $base.'/storage');
    $footprint = new InstalledFootprint($paths);
    $check = new FootprintCheck(
        $paths,
        $footprint,
        new StaleClassMap($base.'/storage/stale.json', $base.'/nope.php', MagnaServiceProvider::VERSION),
        new ConfigDrift($base),
        $plugins ?? app(PluginCompatibilityCheck::class),
    );

    return [$paths, $footprint, $check];
}

function footprintLabels(FootprintCheck $check): array
{
    return array_column($check->warnings(), 'label');
}

it('warns when nothing recorded what the running release delivered', function (): void {
    [$paths, $footprint, $check] = footprintCheckPaths();

    try {
        expect($check->deliveredByOlderUpdater())->toBeTrue()
            ->and(footprintLabels($check))->toHaveCount(1)
            ->and(footprintLabels($check)[0])->toContain('delivered by an older updater');
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('warns when the recorded delivery is an older version than the one running', function (): void {
    [$paths, $footprint, $check] = footprintCheckPaths();

    try {
        $footprint->record('1.0.0', 'update', 'r0', null, ['src/Magna']);

        expect($check->deliveredByOlderUpdater())->toBeTrue()
            ->and(footprintLabels($check)[0])->toContain('last recorded delivery is v1.0.0');
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('warns when a recorded path has gone missing, and is quiet when everything is in place', function (): void {
    [$paths, $footprint, $check] = footprintCheckPaths();

    try {
        $footprint->record(MagnaServiceProvider::VERSION, 'update', 'r1', null, ['src/Magna', 'app']);

        expect($check->deliveredByOlderUpdater())->toBeFalse()
            ->and(footprintLabels($check)[0])->toContain('missing: app');

        mkdir($paths->base('app'), 0777, true);

        expect($check->warnings())->toBe([]);
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('does not alarm when an optional recorded path is absent (the bundled SDK source on a non-hub install)', function (): void {
    [$paths, $footprint, $check] = footprintCheckPaths();

    try {
        // The bundled SDK source is optional: a hub archive carries it, a
        // core-only one does not, and an install that is not a hub has no use
        // for it once vendor/magna-cms/plugin-sdk exists. Recorded as delivered
        // (an archive did carry it), then gone from disk — that is not damage.
        $footprint->record(MagnaServiceProvider::VERSION, 'update', 'r1', null, ['src/Magna', CoreUpdater::SDK_SOURCE_PATH]);

        expect($check->deliveredByOlderUpdater())->toBeFalse()
            ->and($check->warnings())->toBe([]);
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('still alarms for a missing required path even when an optional one is also recorded', function (): void {
    [$paths, $footprint, $check] = footprintCheckPaths();

    try {
        $footprint->record(MagnaServiceProvider::VERSION, 'update', 'r1', null, ['src/Magna', 'app', CoreUpdater::SDK_SOURCE_PATH]);

        $labels = footprintLabels($check);

        expect($labels[0])->toContain('missing: app')
            ->and($labels[0])->not->toContain(CoreUpdater::SDK_SOURCE_PATH);
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

it('warns about an update that switched files and has not finished', function (): void {
    [$paths, $footprint, $check] = footprintCheckPaths();

    try {
        $footprint->record(MagnaServiceProvider::VERSION, 'update', 'r1', null, ['src/Magna']);
        UpdateJournal::create($paths, 'r2', ['to' => '99.0.0'])->transition(RunState::FinalizePending);

        expect(footprintLabels($check)[0])->toContain('has switched files but has not finished');
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

/*
 * A key the site's file still sets that core replaced by name is a setting
 * nothing reads — the renamed-key rule's one blind spot, made visible.
 */
it('warns when the site config still sets a key core has replaced', function (): void {
    [$paths, $footprint, $check] = footprintCheckPaths();

    try {
        $footprint->record(MagnaServiceProvider::VERSION, 'update', 'r1', null, ['src/Magna']);
        file_put_contents($paths->base('config/magna.php'), "<?php\n\nreturn ['updater' => ['require_signed_checksum' => false]];\n");

        $labels = footprintLabels($check);

        expect($labels)->toHaveCount(1)
            ->and($labels[0])->toContain('require_signed_checksum')
            ->and($labels[0])->toContain('nothing reads any more');
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});

/*
 * A plugin that declares no support for the running core is said, never
 * disabled for the declaration alone: it keeps running, and the next update
 * is where the declaration becomes a refusal.
 */
it('names enabled plugins whose manifest rules out the running core', function (): void {
    $base = sys_get_temp_dir().'/magna-fpcheck-'.bin2hex(random_bytes(6));
    $pluginDir = $base.'/plugins-dev/acme/old';
    mkdir($pluginDir, 0777, true);

    $manifest = [
        'name' => 'acme/old',
        'displayName' => 'Old Plugin',
        'description' => 'Written for a core that is gone.',
        'version' => '1.0.0',
        'author' => 'Acme',
        'license' => 'MIT',
        'compat' => ['magna' => '<1.0.0', 'php' => '^8.3'],
        'entry' => 'Acme\\Old\\Plugin',
        'permissions' => [],
    ];
    file_put_contents($pluginDir.'/magna.json', (string) json_encode($manifest));

    // Discovery reads plugins-dev under its own base path and trusts only
    // what that base's composer.json wires in — so the fixture is a whole
    // install root, and the manager is rebuilt over it.
    file_put_contents($base.'/composer.json', (string) json_encode([
        'repositories' => [['type' => 'path', 'url' => 'plugins-dev/acme/old']],
    ]));
    app()->instance(PluginDiscovery::class, new PluginDiscovery($base));
    app()->forgetInstance(PluginManager::class);

    [$paths, $footprint, $check] = footprintCheckPaths($base, app(PluginCompatibilityCheck::class));

    PluginRecord::query()->create([
        'name' => $manifest['name'],
        'display_name' => 'Old Plugin',
        'version' => '1.0.0',
        'enabled' => true,
        'base_path' => $pluginDir,
        'manifest' => $manifest,
    ]);

    try {
        $footprint->record(MagnaServiceProvider::VERSION, 'update', 'r1', null, ['src/Magna']);

        $labels = footprintLabels($check);

        expect($labels)->toHaveCount(1)
            ->and($labels[0])->toContain('Old Plugin')
            ->and($labels[0])->toContain('declare no support for v'.MagnaServiceProvider::VERSION);
    } finally {
        app()->forgetInstance(PluginDiscovery::class);
        app()->forgetInstance(PluginManager::class);
        (new Filesystem)->remove($paths->basePath);
    }
});
