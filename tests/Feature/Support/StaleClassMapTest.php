<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Magna\Support\StaleClassMap;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A core update overlays src/Magna and never vendor/, so the optimised
 * classmap keeps naming files a release deleted. Composer returns those paths
 * without looking, the include fails, and even class_exists() on the old name
 * is a 500. This is the loader-side repair.
 */
function staleClassMapFixture(): string
{
    $dir = sys_get_temp_dir().'/magna-stale-classmap-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    return $dir;
}

function removeStaleClassMapFixture(string $dir): void
{
    foreach (glob($dir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

/**
 * A ClassLoader appended to the chain, so the real Composer loader still
 * answers first for everything else.
 *
 * @param  array<string, string>  $classMap
 */
function registerProbeLoader(array $classMap): ClassLoader
{
    $loader = new ClassLoader;
    $loader->addClassMap($classMap);
    $loader->register();

    return $loader;
}

it('disarms a core entry whose file is gone so class_exists answers false', function (): void {
    $dir = staleClassMapFixture();
    $suffix = bin2hex(random_bytes(3));
    $liveClass = 'Magna\\StaleProbe\\Live'.$suffix;
    $goneClass = 'Magna\\StaleProbe\\Gone'.$suffix;
    $appGoneClass = 'App\\StaleProbe\\Gone'.$suffix;

    file_put_contents($dir.'/Live.php', "<?php\n\nnamespace Magna\\StaleProbe;\n\nfinal class Live{$suffix} {}\n");

    $loader = registerProbeLoader([
        $liveClass => $dir.'/Live.php',
        $goneClass => $dir.'/Gone.php',
        $appGoneClass => $dir.'/AppGone.php',
    ]);

    try {
        $map = new StaleClassMap($dir.'/cache.json', $dir.'/no-such-classmap.php', '9.9.9');

        expect($map->neutralise($loader))->toBe([$appGoneClass, $goneClass]);

        // No include, no warning, no ErrorException: just the truthful answer.
        expect(class_exists($goneClass))->toBeFalse()
            ->and(class_exists($appGoneClass))->toBeFalse()
            ->and(class_exists($liveClass))->toBeTrue();

        expect(is_file($dir.'/cache.json'))->toBeTrue();
    } finally {
        $loader->unregister();
        removeStaleClassMapFixture($dir);
    }
});

it('leaves entries outside the core prefixes alone', function (): void {
    $dir = staleClassMapFixture();
    $suffix = bin2hex(random_bytes(3));
    $vendorClass = 'Acme\\StaleProbe\\Gone'.$suffix;

    $loader = registerProbeLoader([$vendorClass => $dir.'/Gone.php']);

    try {
        $map = new StaleClassMap($dir.'/cache.json', $dir.'/no-such-classmap.php', '9.9.9');

        expect($map->neutralise($loader))->toBe([]);
        expect($loader->getClassMap()[$vendorClass])->toBe($dir.'/Gone.php');
    } finally {
        $loader->unregister();
        removeStaleClassMapFixture($dir);
    }
});

it('reuses the cached scan while the version and classmap are unchanged', function (): void {
    $dir = staleClassMapFixture();
    $classMapPath = $dir.'/autoload_classmap.php';
    file_put_contents($classMapPath, '<?php return [];');

    file_put_contents($dir.'/cache.json', (string) json_encode([
        'version' => '9.9.9',
        'classmap_mtime' => filemtime($classMapPath),
        'stale' => ['Magna\\StaleProbe\\FromCache'],
    ]));

    $loader = new ClassLoader;

    // The loader has no such entry: the answer can only have come from the cache.
    $map = new StaleClassMap($dir.'/cache.json', $classMapPath, '9.9.9');

    try {
        expect($map->neutralise($loader))->toBe(['Magna\\StaleProbe\\FromCache']);
        expect($loader->getClassMap()['Magna\\StaleProbe\\FromCache'])->toBe('');
    } finally {
        removeStaleClassMapFixture($dir);
    }
});

it('rescans when the core version changes', function (): void {
    $dir = staleClassMapFixture();
    $classMapPath = $dir.'/autoload_classmap.php';
    file_put_contents($classMapPath, '<?php return [];');

    file_put_contents($dir.'/cache.json', (string) json_encode([
        'version' => '1.0.0',
        'classmap_mtime' => filemtime($classMapPath),
        'stale' => ['Magna\\StaleProbe\\FromOldCache'],
    ]));

    $loader = new ClassLoader;
    $loader->addClassMap(['Magna\\StaleProbe\\NowGone' => $dir.'/NowGone.php']);

    $map = new StaleClassMap($dir.'/cache.json', $classMapPath, '2.0.0');

    try {
        expect($map->stale($loader))->toBe(['Magna\\StaleProbe\\NowGone']);

        /** @var array{version: string, stale: list<string>} $rewritten */
        $rewritten = json_decode((string) file_get_contents($dir.'/cache.json'), true);
        expect($rewritten['version'])->toBe('2.0.0')
            ->and($rewritten['stale'])->toBe(['Magna\\StaleProbe\\NowGone']);
    } finally {
        removeStaleClassMapFixture($dir);
    }
});

/*
 * The manifest names every class retired since the oldest release an archive
 * may be applied over. A site whose map still carries one gets it disarmed
 * ahead of the first boot; a site whose vendor/ the release just replaced has
 * a map that names none of them, and must not be told it has ten.
 */
it('primes only the names the classmap on disk still carries', function (): void {
    $dir = staleClassMapFixture();
    $classMapPath = $dir.'/autoload_classmap.php';
    file_put_contents($classMapPath, "<?php return ['Magna\\\\StaleProbe\\\\StillNamed' => '/gone/StillNamed.php'];");

    try {
        $map = new StaleClassMap($dir.'/cache.json', $classMapPath, '9.9.9');
        $map->prime(['Magna\\StaleProbe\\NeverNamed', 'Magna\\StaleProbe\\StillNamed', 'Acme\\NotCore']);

        expect($map->stale())->toBe(['Magna\\StaleProbe\\StillNamed']);

        /** @var array{stale: list<string>} $written */
        $written = json_decode((string) file_get_contents($dir.'/cache.json'), true);
        expect($written['stale'])->toBe(['Magna\\StaleProbe\\StillNamed']);

        // Without a readable map the manifest's word is taken as given.
        $blind = new StaleClassMap($dir.'/cache2.json', $dir.'/no-such-classmap.php', '9.9.9');
        $blind->prime(['Magna\\StaleProbe\\NeverNamed']);

        expect($blind->stale())->toBe(['Magna\\StaleProbe\\NeverNamed']);
    } finally {
        removeStaleClassMapFixture($dir);
    }
});

it('survives an unwritable cache location by simply rescanning', function (): void {
    $loader = new ClassLoader;
    $loader->addClassMap(['Magna\\StaleProbe\\Unwritable' => '/definitely/not/here.php']);

    // A file where the cache DIRECTORY should be: mkdir fails, the write is skipped.
    $blocker = tempnam(sys_get_temp_dir(), 'magna-stale-blocker-');

    try {
        $map = new StaleClassMap($blocker.'/cache.json', '/no/classmap.php', '9.9.9');

        expect($map->neutralise($loader))->toBe(['Magna\\StaleProbe\\Unwritable']);
    } finally {
        @unlink($blocker);
    }
});

/*
 * The incident, against the real loader. This working copy's vendor/composer
 * maps still name the settings pages deleted on 2026-09-12 (a fresh CI vendor
 * does not, and then this is simply a class that never existed). Either way
 * the booted application must answer class_exists() with false and no error.
 */
it('is wired into the booted application', function (): void {
    expect(app(StaleClassMap::class))->toBeInstanceOf(StaleClassMap::class)
        ->and(app(StaleClassMap::class)->stale())->toBeArray();

    expect(class_exists('Magna\\Admin\\Pages\\GeneralSettingsPage'))->toBeFalse();
});
