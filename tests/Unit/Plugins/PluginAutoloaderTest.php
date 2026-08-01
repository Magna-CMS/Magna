<?php

declare(strict_types=1);

use Magna\Plugins\PluginAutoloader;

/**
 * A plugin dropped on disk by the Core Plugin Manager's zip upload is not in
 * vendor/composer/autoload_*, and a host with no Composer binary has nothing
 * to regenerate them with. Without this registration its entry class fails to
 * autoload and bootEnabledPlugins() auto-disables the plugin as "files
 * missing" — install succeeds, then the plugin silently vanishes on the next
 * request.
 */
function makePluginDir(string $namespace): string
{
    $dir = sys_get_temp_dir().'/magna-autoload-'.bin2hex(random_bytes(4));
    mkdir($dir.'/src', 0755, true);
    file_put_contents($dir.'/composer.json', json_encode([
        'name' => 'acme/demo',
        'autoload' => ['psr-4' => [$namespace.'\\' => 'src/']],
    ]));

    return $dir;
}

function removePluginDir(string $dir): void
{
    foreach (glob($dir.'/src/*') ?: [] as $file) {
        unlink($file);
    }
    @unlink($dir.'/composer.json');
    @rmdir($dir.'/src');
    @rmdir($dir);
}

it('autoloads a class from a plugin Composer never saw', function (): void {
    $namespace = 'MagnaAutoloadProbe'.bin2hex(random_bytes(3));
    $dir = makePluginDir($namespace);
    file_put_contents($dir.'/src/Widget.php', "<?php\n\nnamespace {$namespace};\n\nclass Widget {}\n");

    // Deliberately not asserting the class is missing first: Composer's
    // ClassLoader keeps a negative cache (missingClasses), so a failed lookup
    // would never be retried even after the prefix is registered. Prove the
    // starting state with a sibling class instead.
    expect(class_exists($namespace.'\\NotShipped'))->toBeFalse();

    (new PluginAutoloader)->register($dir);

    expect(class_exists($namespace.'\\Widget'))->toBeTrue();

    removePluginDir($dir);
});

it('refuses a psr-4 path that points outside the plugin directory', function (): void {
    // The autoload map arrives inside an uploaded zip. Mapping a namespace at
    // "../../src" would let a plugin's class names resolve to core files.
    $namespace = 'MagnaEscapeProbe'.bin2hex(random_bytes(3));
    $dir = sys_get_temp_dir().'/magna-escape-'.bin2hex(random_bytes(4));
    mkdir($dir.'/plugin', 0755, true);
    mkdir($dir.'/outside', 0755, true);
    file_put_contents($dir.'/outside/Secret.php', "<?php\n\nnamespace {$namespace};\n\nclass Secret {}\n");
    file_put_contents($dir.'/plugin/composer.json', json_encode([
        'autoload' => ['psr-4' => [$namespace.'\\' => '../outside/']],
    ]));

    (new PluginAutoloader)->register($dir.'/plugin');

    expect(class_exists($namespace.'\\Secret'))->toBeFalse();

    unlink($dir.'/outside/Secret.php');
    unlink($dir.'/plugin/composer.json');
    array_map('rmdir', [$dir.'/outside', $dir.'/plugin', $dir]);
});

it('is safe to call repeatedly and on a directory with no composer.json', function (): void {
    $namespace = 'MagnaAutoloadProbe'.bin2hex(random_bytes(3));
    $dir = makePluginDir($namespace);
    file_put_contents($dir.'/src/Thing.php', "<?php\n\nnamespace {$namespace};\n\nclass Thing {}\n");

    $autoloader = new PluginAutoloader;
    $autoloader->register($dir);
    $autoloader->register($dir);
    $autoloader->register($dir.'/nope');

    expect(class_exists($namespace.'\\Thing'))->toBeTrue();

    removePluginDir($dir);
});
