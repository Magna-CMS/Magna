<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

// A core update overlays src/Magna and never vendor/, so a class ADDED by a
// release is absent from the classmap the site was installed with. Composer
// answers "no such class" — optimized maps skip PSR-4, authoritative ones
// refuse it outright — and every request dies with
//
//     Class "Magna\Admin\PluginPanelSurface" not found
//
// even though the file is on disk. That is exactly how v1.3.10 took a site
// down. bootstrap/magna-autoload.php is the fallback that makes new core
// classes loadable regardless of vendor's age.

function magnaFallbackLoader(string $baseDir): callable
{
    /** @var callable(string): callable(string): void $factory */
    $factory = require base_path('bootstrap/magna-autoload.php');

    return $factory($baseDir);
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/magna-fallback-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/Probe', 0777, true);
});

afterEach(function (): void {
    (new Filesystem)->remove($this->root);
});

it('loads a Magna class that Composer knows nothing about', function (): void {
    $name = 'Thing'.bin2hex(random_bytes(3));

    file_put_contents(
        $this->root.'/Probe/'.$name.'.php',
        "<?php\n\nnamespace Magna\\Probe;\n\nclass {$name} {}\n"
    );

    $class = 'Magna\\Probe\\'.$name;

    expect(class_exists($class, false))->toBeFalse();

    magnaFallbackLoader($this->root)($class);

    expect(class_exists($class, false))->toBeTrue();
});

it('ignores classes outside the Magna namespace', function (): void {
    // Cheap by design: this runs after Composer on every miss, so anything
    // that is not ours must cost one string comparison and nothing more.
    file_put_contents($this->root.'/Probe/Foreign.php', "<?php\n\nnamespace Other;\n\nclass Foreign {}\n");

    magnaFallbackLoader($this->root)('Other\\Probe\\Foreign');

    expect(class_exists('Other\\Probe\\Foreign', false))->toBeFalse();
});

it('refuses a name that resolves outside the source tree', function (): void {
    // Not every escape spells "..": a symlink, or a separator the namespace
    // mapping did not expect, can land the resolved path elsewhere. The loader
    // requires only files that really sit under src/Magna.
    $outside = dirname($this->root).'/magna-fallback-outside.php';
    file_put_contents($outside, "<?php\n\nnamespace Magna\\Probe;\n\nclass Outside {}\n");

    $link = $this->root.'/Probe/Linked.php';

    if (! @symlink($outside, $link)) {
        @unlink($outside);
        test()->markTestSkipped('This environment does not allow creating symlinks.');
    }

    try {
        magnaFallbackLoader($this->root)('Magna\\Probe\\Linked');

        expect(class_exists('Magna\\Probe\\Outside', false))->toBeFalse();
    } finally {
        @unlink($link);
        @unlink($outside);
    }
});

it('refuses a class name that would climb out of the source tree', function (): void {
    $escape = dirname($this->root).'/magna-fallback-escape.php';
    file_put_contents($escape, "<?php\n\nnamespace Magna;\n\nclass Escaped {}\n");

    try {
        // The class name is turned straight into a path, so traversal has to
        // be rejected rather than resolved.
        magnaFallbackLoader($this->root)('Magna\\..\\magna-fallback-escape');

        expect(class_exists('Magna\\Escaped', false))->toBeFalse();
    } finally {
        @unlink($escape);
    }
});
