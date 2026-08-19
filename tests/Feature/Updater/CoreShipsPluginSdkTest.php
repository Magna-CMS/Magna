<?php

declare(strict_types=1);

use Magna\Plugins\PluginsServiceProvider;
use Magna\Updater\CoreUpdater;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A core update has to carry the SDK, and the SDK's contracts have to be
 * loadable once it arrives.
 *
 * Both halves are needed and neither is sufficient. Core shipped without the
 * SDK for a long time, so a plugin written against a new contract could not be
 * enabled on an updated site — the interface it implements was simply not
 * there, and PHP resolves an `implements` clause the moment the class loads:
 *
 *   Failed to enable plugin
 *   Interface "Magna\Contracts\RegistersCaptchaSurfaces" not found
 *
 * Copying the files alone would not have been enough either. `CoreUpdater`
 * cannot regenerate `vendor/composer`'s maps — a host with no Composer binary
 * has nothing to regenerate them with — so a contract in a namespace those
 * maps predate would land on disk and stay unloadable.
 */
it('overlays the plugin SDK on update', function (): void {
    // The rest of vendor/ is deliberately excluded: a customer's vendor holds
    // plugin packages a core release knows nothing about. The SDK is the one
    // vendor path core owns outright.
    expect(CoreUpdater::coreOwnedPaths())->toContain(CoreUpdater::SDK_PATH)
        ->and(CoreUpdater::SDK_PATH)->toBe('vendor/magna-cms/plugin-sdk');

    foreach (CoreUpdater::coreOwnedPaths() as $path) {
        expect($path)->not->toBe('vendor')
            ->and($path)->not->toBe('composer.json')
            ->and($path)->not->toBe('composer.lock');
    }
});

/**
 * Refreshing vendor/ alone does not hold on a hub.
 *
 * A hub resolves the SDK through a path repository whose source is staged in
 * the archive, and Composer installs it with `symlink: false` — vendor/ gets a
 * copy. So the vendor overlay survives only until the next Composer run, and
 * `composer require` is exactly what a marketplace plugin install performs.
 * The old source is copied back over the new contracts, and enabling the
 * plugin fails again on the interface that just disappeared.
 *
 * This was found live: a hub on 1.3.20 had 23 contracts under vendor/ and the
 * original 10 under bundled/, one plugin install away from losing them.
 */
it('overlays the SDK source a hub rebuilds vendor from', function (): void {
    expect(CoreUpdater::coreOwnedPaths())->toContain(CoreUpdater::SDK_SOURCE_PATH)
        ->and(CoreUpdater::SDK_SOURCE_PATH)->toBe('bundled/magna-cms/plugin-sdk');

    // Both halves of the same package, or the copy and its source drift apart
    // again the moment one of them moves.
    expect(CoreUpdater::SDK_SOURCE_PATH)->toEndWith('magna-cms/plugin-sdk')
        ->and(CoreUpdater::SDK_PATH)->toEndWith('magna-cms/plugin-sdk');
});

it('loads an SDK contract in a namespace the dumped autoload maps have never seen', function (): void {
    $sdk = base_path(CoreUpdater::SDK_PATH);
    $manifest = $sdk.'/composer.json';

    if (! is_file($manifest)) {
        test()->markTestSkipped('The SDK is not installed under vendor/ in this checkout.');
    }

    /*
     * A whole namespace Composer has never dumped a rule for — which is the
     * case that actually bites. `Magna\Contracts\` would prove nothing here:
     * this checkout's vendor/composer already maps it, so PSR-4 would find a
     * new contract with or without the fix. An updated site is in this state
     * instead: the SDK on disk declares a namespace its vendor/composer maps
     * predate, and only something that reads the SDK's own composer.json at
     * runtime can resolve it.
     */
    $namespace = 'Magna\\SdkNamespaceProbe\\';
    $directory = $sdk.'/src/SdkNamespaceProbe';
    $original = (string) file_get_contents($manifest);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
    $decoded['autoload']['psr-4'][$namespace] = 'src/SdkNamespaceProbe/';

    mkdir($directory, 0777, true);
    file_put_contents(
        $directory.'/ProbeContract.php',
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace Magna\\SdkNamespaceProbe;\n\ninterface ProbeContract {}\n",
    );
    file_put_contents($manifest, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        expect(interface_exists('Magna\\SdkNamespaceProbe\\ProbeContract'))->toBeFalse(
            'Composer already knows this namespace, so the test would prove nothing.',
        );

        // Booting the provider is what proves the wiring: nothing here
        // registers the namespace, so the interface can only resolve if
        // PluginsServiceProvider::boot() registered the SDK's own PSR-4 rules.
        app()->register(new PluginsServiceProvider(app()), force: true);

        expect(interface_exists('Magna\\SdkNamespaceProbe\\ProbeContract'))->toBeTrue();
    } finally {
        file_put_contents($manifest, $original);
        @unlink($directory.'/ProbeContract.php');
        @rmdir($directory);
    }
});
