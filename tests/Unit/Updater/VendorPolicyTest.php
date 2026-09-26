<?php

declare(strict_types=1);

use Magna\Updater\Engine\VendorPolicy;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Whether a site's vendor/ can simply become the archive's: yes when nothing
 * in it is the site's own, no when the Marketplace put a package there that
 * the release's composer.json knows nothing about.
 */
function vendorFixture(array $composer, array $installed): string
{
    $root = sys_get_temp_dir().'/magna-vendor-'.bin2hex(random_bytes(4));
    mkdir($root.'/vendor/composer', 0777, true);
    file_put_contents($root.'/composer.json', (string) json_encode($composer));
    file_put_contents($root.'/vendor/composer/installed.json', (string) json_encode(['packages' => $installed, 'dev' => false]));

    return $root;
}

function packageRecord(string $name, string $version): array
{
    return ['name' => $name, 'version' => $version, 'version_normalized' => ltrim($version, 'v').'.0'];
}

it('sees a site that already holds the archive as matching', function (): void {
    $archive = vendorFixture(['require' => ['laravel/framework' => '^13']], [packageRecord('laravel/framework', 'v13.23.0')]);
    $site = vendorFixture(['require' => ['laravel/framework' => '^13']], [packageRecord('laravel/framework', 'v13.23.0')]);

    try {
        $policy = new VendorPolicy($site, $archive);

        expect($policy->siteMatchesArchive())->toBeTrue()
            ->and($policy->foreignPackages())->toBe([])
            ->and($policy->foreignRepositories())->toBe([]);
    } finally {
        (new Filesystem)->remove([$archive, $site]);
    }
});

it('sees a site on older packages as not matching, with nothing foreign to put back', function (): void {
    $archive = vendorFixture(['require' => ['laravel/framework' => '^13']], [packageRecord('laravel/framework', 'v13.30.0')]);
    $site = vendorFixture(['require' => ['laravel/framework' => '^13']], [packageRecord('laravel/framework', 'v13.23.0')]);

    try {
        $policy = new VendorPolicy($site, $archive);

        expect($policy->siteMatchesArchive())->toBeFalse()
            ->and($policy->foreignPackages())->toBe([]);
    } finally {
        (new Filesystem)->remove([$archive, $site]);
    }
});

it('names the packages the site asks for that the release does not, at the versions it has them', function (): void {
    $archive = vendorFixture(['require' => ['php' => '^8.3', 'laravel/framework' => '^13']], [packageRecord('laravel/framework', 'v13.30.0')]);
    $site = vendorFixture(
        ['require' => ['php' => '^8.3', 'ext-zip' => '*', 'laravel/framework' => '^13', 'acme/forum' => '^2.1', 'acme/unrecorded' => '*']],
        [packageRecord('laravel/framework', 'v13.23.0'), packageRecord('acme/forum', 'v2.1.4')],
    );

    try {
        expect((new VendorPolicy($site, $archive))->foreignPackages())->toBe([
            'acme/forum' => 'v2.1.4',
            'acme/unrecorded' => '*',
        ]);
    } finally {
        (new Filesystem)->remove([$archive, $site]);
    }
});

it('names repositories the site added, by type and url', function (): void {
    $archive = vendorFixture(['require' => [], 'repositories' => [['type' => 'composer', 'url' => 'https://repo.example.test']]], []);
    $site = vendorFixture([
        'require' => [],
        'repositories' => [
            ['type' => 'composer', 'url' => 'https://repo.example.test'],
            ['type' => 'path', 'url' => 'plugins-dev\\acme\\forum'],
        ],
    ], []);
    mkdir($site.'/plugins-dev/acme/forum', 0777, true);

    try {
        expect((new VendorPolicy($site, $archive))->foreignRepositories())->toBe(['path:plugins-dev/acme/forum']);
    } finally {
        (new Filesystem)->remove([$archive, $site]);
    }
});

/*
 * Archives built before 1.3.25 shipped the build machine's absolute path to
 * the SDK as a repository, and every site installed from one still carries
 * it. A path repository with no directory behind it serves nothing and is
 * not held against the site.
 */
it('ignores a path repository whose directory is not on the site', function (): void {
    $archive = vendorFixture(['require' => []], []);
    $site = vendorFixture([
        'require' => [],
        'repositories' => [
            ['type' => 'path', 'url' => 'C:/Users/builder/magna-plugin-sdk', 'options' => ['symlink' => false]],
            ['type' => 'path', 'url' => 'plugins-dev/acme/gone'],
            ['type' => 'vcs', 'url' => 'https://git.example.test/acme/forum'],
        ],
    ], []);

    try {
        expect((new VendorPolicy($site, $archive))->foreignRepositories())->toBe(['vcs:https://git.example.test/acme/forum']);
    } finally {
        (new Filesystem)->remove([$archive, $site]);
    }
});

it('treats an archive without vendor as nothing to replace', function (): void {
    $archive = sys_get_temp_dir().'/magna-vendor-bare-'.bin2hex(random_bytes(4));
    mkdir($archive, 0777, true);
    $site = vendorFixture(['require' => ['acme/forum' => '^1']], [packageRecord('acme/forum', 'v1.0.0')]);

    try {
        expect((new VendorPolicy($site, $archive))->siteMatchesArchive())->toBeTrue();
    } finally {
        (new Filesystem)->remove([$archive, $site]);
    }
});
