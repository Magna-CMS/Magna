<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3).'/bin/support/release-manifest.php';

/**
 * The classes a release retired travel in its manifest, so every site that
 * updates over them can disarm the classmap entries Composer would otherwise
 * include unchecked. The list is computed from git at build time; these
 * cover the mapping and the diff reading, and one real query against this
 * repository's history.
 */
it('maps a core source path to the class it declares', function (): void {
    expect(release_class_from_path('src/Magna/Admin/Pages/GeneralSettingsPage.php'))->toBe('Magna\\Admin\\Pages\\GeneralSettingsPage')
        ->and(release_class_from_path('app/Providers/AppServiceProvider.php'))->toBe('App\\Providers\\AppServiceProvider')
        ->and(release_class_from_path('src\\Magna\\Support\\Runtime.php'))->toBe('Magna\\Support\\Runtime')
        ->and(release_class_from_path('src/Magna/Auth/resources/views/login.blade.php'))->toBeNull()
        ->and(release_class_from_path('resources/views/welcome.blade.php'))->toBeNull()
        ->and(release_class_from_path('src/Magna/Config/defaults/magna.php'))->toBe('Magna\\Config\\defaults\\magna')
        ->and(release_class_from_path('database/migrations/2026_01_01_000000_x.php'))->toBeNull();
});

it('reads deletions and the old side of a rename from a name-status diff', function (): void {
    $lines = [
        "D\tsrc/Magna/Admin/Pages/GeneralSettingsPage.php",
        "R081\tsrc/Magna/Delivery/Controllers/DeliveryRequestContext.php\tsrc/Magna/Delivery/DeliveryRequestContext.php",
        "M\tsrc/Magna/Updater/CoreUpdater.php",
        "A\tsrc/Magna/Updater/UpdatePaths.php",
        "D\tsrc/Magna/Auth/resources/views/login.blade.php",
        "D\tapp/Legacy/Thing.php",
        '',
    ];

    expect(release_classes_from_diff($lines))->toBe([
        'App\\Legacy\\Thing',
        'Magna\\Admin\\Pages\\GeneralSettingsPage',
        'Magna\\Delivery\\Controllers\\DeliveryRequestContext',
    ]);
});

it('finds the settings pages this repository deleted after v1.3.16', function (): void {
    $root = dirname(__DIR__, 3);

    $removed = release_removed_classes($root, 'v1.3.16');

    if ($removed === null) {
        test()->markTestSkipped('git could not diff against v1.3.16 here (shallow clone or no git); the runtime scan covers this case.');
    }

    foreach (['Api', 'Content', 'General', 'Localization', 'Media', 'Security', 'Storage', 'Url'] as $page) {
        expect($removed)->toContain('Magna\\Admin\\Pages\\'.$page.'SettingsPage');
    }

    expect($removed)->not->toContain('Magna\\Updater\\CoreUpdater');
});

it('answers null rather than an empty list when git cannot diff', function (): void {
    $notARepo = sys_get_temp_dir().'/magna-not-a-repo-'.bin2hex(random_bytes(4));
    mkdir($notARepo, 0777, true);

    try {
        expect(release_removed_classes($notARepo, 'v1.2.0'))->toBeNull();
    } finally {
        @rmdir($notARepo);
    }
});
