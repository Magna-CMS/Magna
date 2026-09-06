<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Plugins\PluginAutoloader;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// A site that UPDATED core keeps its old vendor/ — CoreUpdater never
// replaces it — so an archive built with --classmap-authoritative leaves
// that loader in place forever. In that mode Composer answers "no" for any
// class outside its map without ever consulting PSR-4 rules, so registering
// a plugin's namespace on it achieved nothing and every licensed install
// died with "Plugin entry class […] does not exist". PluginAutoloader must
// therefore be able to load a plugin class on its own.

function fakePluginOnDisk(string $namespace, string $class): string
{
    $base = sys_get_temp_dir().'/magna-plugin-'.bin2hex(random_bytes(6));
    mkdir($base.'/src', 0777, true);

    file_put_contents($base.'/composer.json', json_encode([
        'name' => 'acme/fake',
        'autoload' => ['psr-4' => [$namespace.'\\' => 'src/']],
    ]));

    file_put_contents(
        $base.'/src/'.$class.'.php',
        "<?php\n\nnamespace {$namespace};\n\nclass {$class} {}\n",
    );

    return $base;
}

it('loads a plugin class even when Composer refuses every PSR-4 lookup', function (): void {
    // Reproduce the updated-site condition on the real running loader.
    $loader = null;
    foreach (spl_autoload_functions() ?: [] as $function) {
        if (is_array($function) && $function[0] instanceof ClassLoader) {
            $loader = $function[0];
            break;
        }
    }

    expect($loader)->not->toBeNull();

    $namespace = 'MagnaAuthoritativeProbe'.bin2hex(random_bytes(3));
    $base = fakePluginOnDisk($namespace, 'ProbePlugin');

    $loader->setClassMapAuthoritative(true);

    try {
        app(PluginAutoloader::class)->register($base);

        expect(class_exists($namespace.'\\ProbePlugin'))->toBeTrue();
    } finally {
        $loader->setClassMapAuthoritative(false);
        @unlink($base.'/src/ProbePlugin.php');
        @unlink($base.'/composer.json');
        @rmdir($base.'/src');
        @rmdir($base);
    }
});

/*
 * The other half of the same problem. Above, Composer says "no" and the
 * fallback answers. Here Composer says "yes" and is wrong: findFile() reads its
 * classmap before any PSR-4 rule and hands back that path without checking it
 * exists, so the include fatals and no later autoloader is ever consulted.
 *
 * Seen on a live install: a plugin Composer had once mapped into
 * vendor/{vendor}/{package} as a path repository, whose directory later went
 * with a rolled-back install. Every enable died on "include(.../vendor/magna/
 * restaurant-finance/src/RestaurantFinancePlugin.php): Failed to open stream"
 * while the files sat in plugins-dev/ the whole time, and the host had no
 * Composer binary to regenerate the map with.
 */
it('loads a plugin class when Composer has a stale classmap pointing at a deleted file', function (): void {
    $loader = null;
    foreach (spl_autoload_functions() ?: [] as $function) {
        if (is_array($function) && $function[0] instanceof ClassLoader) {
            $loader = $function[0];
            break;
        }
    }

    expect($loader)->not->toBeNull();

    $namespace = 'MagnaStaleMapProbe'.bin2hex(random_bytes(3));
    $base = fakePluginOnDisk($namespace, 'StalePlugin');
    $class = $namespace.'\\StalePlugin';

    // Where Composer thinks the class is: the vendor/ copy that is not there.
    $loader->addClassMap([$class => $base.'/../vendor-gone/src/StalePlugin.php']);

    try {
        app(PluginAutoloader::class)->register($base);

        expect(class_exists($class))->toBeTrue();
    } finally {
        @unlink($base.'/src/StalePlugin.php');
        @unlink($base.'/composer.json');
        @rmdir($base.'/src');
        @rmdir($base);
    }
});

it('leaves a classmap entry alone when the file it names is really there', function (): void {
    $loader = null;
    foreach (spl_autoload_functions() ?: [] as $function) {
        if (is_array($function) && $function[0] instanceof ClassLoader) {
            $loader = $function[0];
            break;
        }
    }

    expect($loader)->not->toBeNull();

    $namespace = 'MagnaLiveMapProbe'.bin2hex(random_bytes(3));
    $base = fakePluginOnDisk($namespace, 'LivePlugin');
    $class = $namespace.'\\LivePlugin';

    $loader->addClassMap([$class => $base.'/src/LivePlugin.php']);

    try {
        app(PluginAutoloader::class)->register($base);

        expect($loader->getClassMap()[$class])->toBe($base.'/src/LivePlugin.php');
    } finally {
        @unlink($base.'/src/LivePlugin.php');
        @unlink($base.'/composer.json');
        @rmdir($base.'/src');
        @rmdir($base);
    }
});

it('ignores classes that belong to no installed plugin', function (): void {
    $namespace = 'MagnaAuthoritativeProbe'.bin2hex(random_bytes(3));
    $base = fakePluginOnDisk($namespace, 'OtherPlugin');

    try {
        app(PluginAutoloader::class)->register($base);

        expect(class_exists('Totally\\Unrelated\\Thing'))->toBeFalse();
    } finally {
        @unlink($base.'/src/OtherPlugin.php');
        @unlink($base.'/composer.json');
        @rmdir($base.'/src');
        @rmdir($base);
    }
});
