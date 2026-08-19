<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3).'/bin/support/release-composer.php';

/**
 * Guards the composer.json rules bin/build-release.php applies. Each assertion
 * here is a bug that shipped in a real archive:
 *
 *  - hub stopped claiming its bundled plugins, so `composer require` (what the
 *    Marketplace runs to install a plugin) pruned Marketplace out of vendor/;
 *  - a bundled plugin stayed in require-dev, so --no-dev dropped it;
 *  - require-dev shipped, so the same command pulled Pest/PHPStan/Psalm onto
 *    production;
 *  - a bundled source was pointed at a build-machine path no target could
 *    resolve.
 */
function workingCopyComposer(): array
{
    return [
        'require' => [
            'php' => '^8.3',
            'laravel/framework' => '^13.8',
            'magna-cms/docs' => '@dev',
            'magna-cms/plugin-sdk' => '@dev',
        ],
        'require-dev' => [
            'pestphp/pest' => '^4.7',
            'magna-cms/marketplace' => '@dev',
            'magna/plugin-manager' => 'dev-main',
            'roya/dms' => '@dev',
            'roya/erp' => '@dev',
        ],
        'repositories' => [
            ['type' => 'path', 'url' => 'plugins-dev/roya/dms'],
            ['type' => 'path', 'url' => '../magna-plugin-sdk'],
            ['type' => 'path', 'url' => 'plugins-dev/magna/marketplace'],
            ['type' => 'composer', 'url' => 'https://repo.example.test'],
        ],
    ];
}

/** Stands in for reading each path repository's own composer.json. */
function fakeResolver(): callable
{
    return static fn (string $url): ?string => match (true) {
        str_contains($url, 'roya/dms') => 'roya/dms',
        str_contains($url, 'magna-plugin-sdk') => 'magna-cms/plugin-sdk',
        str_contains($url, 'magna/marketplace') => 'magna-cms/marketplace',
        default => null,
    };
}

const ALL_PLUGINS = ['magna-cms/docs', 'magna-cms/marketplace', 'magna/plugin-manager', 'roya/dms', 'roya/erp'];
const HUB_BUNDLED = ['magna-cms/docs', 'magna-cms/marketplace', 'magna/plugin-manager'];

it('hub keeps every bundled plugin in require, not require-dev', function (): void {
    $strip = array_values(array_diff(ALL_PLUGINS, HUB_BUNDLED));

    $result = release_apply_plugin_profile(workingCopyComposer(), $strip, HUB_BUNDLED);

    foreach (HUB_BUNDLED as $package) {
        expect($result['require'])->toHaveKey($package)
            ->and($result['require-dev'] ?? [])->not->toHaveKey($package);
    }
});

it('hub strips only the client-specific plugins', function (): void {
    $strip = array_values(array_diff(ALL_PLUGINS, HUB_BUNDLED));

    $result = release_apply_plugin_profile(workingCopyComposer(), $strip, HUB_BUNDLED);

    expect($result['require'] ?? [])->not->toHaveKey('roya/erp')
        ->and($result['require-dev'] ?? [])->not->toHaveKey('roya/dms')
        ->and($result['require'])->toHaveKey('laravel/framework');
});

it('core strips every plugin but keeps the SDK', function (): void {
    $result = release_apply_plugin_profile(workingCopyComposer(), ALL_PLUGINS, []);

    foreach (ALL_PLUGINS as $package) {
        expect($result['require'] ?? [])->not->toHaveKey($package)
            ->and($result['require-dev'] ?? [])->not->toHaveKey($package);
    }

    expect($result['require'])->toHaveKey('magna-cms/plugin-sdk');
});

it('drops path repositories for stripped packages and keeps the rest', function (): void {
    $strip = array_values(array_diff(ALL_PLUGINS, HUB_BUNDLED));

    $result = release_filter_path_repositories(workingCopyComposer(), $strip, fakeResolver());
    $urls = array_column($result['repositories'], 'url');

    expect($urls)->not->toContain('plugins-dev/roya/dms')
        ->and($urls)->toContain('plugins-dev/magna/marketplace')   // bundled — Composer still resolves it
        ->and($urls)->toContain('../magna-plugin-sdk')             // not a plugin
        ->and($urls)->toContain('https://repo.example.test');      // untouched: not a path repo
});

it('bundles a plugin under plugins-dev and a non-plugin elsewhere', function (): void {
    // plugins-dev/{package} is where PluginSource looks for the writable source
    // behind a vendor mirror, so the layout is load-bearing, not cosmetic.
    expect(release_bundle_relative_path('magna-cms/marketplace', 'C:/dev/magna-cms/plugins-dev/magna/marketplace'))
        ->toBe('plugins-dev/magna-cms/marketplace')
        ->and(release_bundle_relative_path('magna-cms/plugin-sdk', 'C:/dev/magna-plugin-sdk'))
        ->toBe('bundled/magna-cms/plugin-sdk');
});

it('never ships require-dev', function (): void {
    $result = release_drop_require_dev(workingCopyComposer());

    expect($result)->not->toHaveKey('require-dev')
        ->and($result['require'])->toHaveKey('laravel/framework');
});

// v1.3.19 shipped composer.json with `"url": "C:/Users/.../magna-plugin-sdk"`,
// so every Composer command on a released site died with "The `url` supplied
// for the path (…) repository does not exist" — including the `composer
// require` the Marketplace runs, which is why no plugin could be installed.
it('ships no path repositories and no build-machine urls for a plain release', function (): void {
    $result = release_publicise_path_repositories(
        workingCopyComposer(),
        fakeResolver(),
        static fn (string $url): ?string => str_contains($url, 'magna-plugin-sdk') ? '^1.0' : null,
    );

    $types = array_column($result['repositories'], 'type');

    expect($types)->not->toContain('path')
        ->and($types)->toContain('composer')                        // untouched
        ->and($result['require']['magna-cms/plugin-sdk'])->toBe('^1.0');
});

it('falls back to any published version when a path source declares no line', function (): void {
    $result = release_publicise_path_repositories(
        workingCopyComposer(),
        fakeResolver(),
        static fn (string $url): ?string => null,
    );

    expect($result['require']['magna-cms/plugin-sdk'])->toBe('*');
});

it('leaves a public version constraint alone', function (): void {
    $composer = workingCopyComposer();
    $composer['require']['magna-cms/plugin-sdk'] = '^1.3';

    $result = release_publicise_path_repositories(
        $composer,
        fakeResolver(),
        static fn (string $url): ?string => '^9.9',
    );

    expect($result['require']['magna-cms/plugin-sdk'])->toBe('^1.3');
});

it('reads the public constraint from a source version or its branch alias', function (): void {
    expect(release_public_constraint(['version' => 'v2.4.1']))->toBe('^2.4')
        ->and(release_public_constraint(['extra' => ['branch-alias' => ['dev-main' => '1.x-dev']]]))->toBe('^1.0')
        ->and(release_public_constraint(['extra' => ['branch-alias' => ['dev-next' => '2.3.x-dev']]]))->toBe('^2.3')
        ->and(release_public_constraint([]))->toBeNull();
});
