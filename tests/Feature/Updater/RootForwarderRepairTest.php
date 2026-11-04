<?php

declare(strict_types=1);

use Magna\Updater\RootForwarderRepair;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The forwarder a site already has. No update swaps the file, so this is the
 * only way a correction reaches an installed site, and it must take nothing
 * else in the file with it.
 */
const DENYING_HTACCESS = 'RewriteRule ^(app|bootstrap|config|database|lang|resources|routes|schemas|src|storage|tests|vendor)(/|$) - [F,L]';
const DENYING_WEB_CONFIG = '<match url="^(app|bootstrap|config|database|lang|resources|routes|schemas|src|storage|tests|vendor|\.env|\.git)(/|$)" />';

function forwarderSite(): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-forwarder-repair-'.bin2hex(random_bytes(6));
    mkdir($base.'/storage', 0777, true);

    return new UpdatePaths($base, $base.'/storage');
}

function repairForwarder(UpdatePaths $paths): array
{
    return (new RootForwarderRepair(new Filesystem, $paths))->repair();
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/magna-forwarder-repair-*') ?: [] as $site) {
        (new Filesystem)->remove($site);
    }
});

it('takes storage off the deny list and leaves the operator\'s own rules', function (): void {
    $paths = forwarderSite();
    file_put_contents($paths->base('.htaccess'), "# BEGIN host rules\nHeader set X-Host yes\r\n# END host rules\n    ".DENYING_HTACCESS."\n    RewriteRule ^(.*)$ public/$1 [L]\n");

    expect(repairForwarder($paths))->toBe(['repaired' => ['.htaccess'], 'failed' => []]);

    $contents = (string) file_get_contents($paths->base('.htaccess'));

    expect($contents)->toBe("# BEGIN host rules\nHeader set X-Host yes\r\n# END host rules\n    RewriteRule ^(app|bootstrap|config|database|lang|resources|routes|schemas|src|tests|vendor)(/|$) - [F,L]\n    RewriteRule ^(.*)$ public/$1 [L]\n");
});

it('corrects web.config the same way', function (): void {
    $paths = forwarderSite();
    file_put_contents($paths->base('web.config'), "<rule>\n    ".DENYING_WEB_CONFIG."\n</rule>\n");

    expect(repairForwarder($paths))->toBe(['repaired' => ['web.config'], 'failed' => []])
        ->and((string) file_get_contents($paths->base('web.config')))->not->toContain('storage');
});

it('writes what a fresh archive ships', function (): void {
    $paths = forwarderSite();
    file_put_contents($paths->base('.htaccess'), DENYING_HTACCESS);
    file_put_contents($paths->base('web.config'), DENYING_WEB_CONFIG);

    repairForwarder($paths);

    $builder = (string) file_get_contents(dirname(__DIR__, 3).'/bin/build-release.php');

    expect($builder)->toContain((string) file_get_contents($paths->base('.htaccess')))
        ->and($builder)->toContain((string) file_get_contents($paths->base('web.config')))
        ->and($builder)->not->toContain(DENYING_HTACCESS)
        ->and($builder)->not->toContain(DENYING_WEB_CONFIG);
});

it('does nothing a second time', function (): void {
    $paths = forwarderSite();
    file_put_contents($paths->base('.htaccess'), DENYING_HTACCESS);

    repairForwarder($paths);

    expect(repairForwarder($paths))->toBe(['repaired' => [], 'failed' => []]);
});

it('leaves a deny list the operator rewrote', function (): void {
    $paths = forwarderSite();
    $rewritten = 'RewriteRule ^(app|storage|vendor)(/|$) - [F,L]';
    file_put_contents($paths->base('.htaccess'), $rewritten);

    expect(repairForwarder($paths))->toBe(['repaired' => [], 'failed' => []])
        ->and((string) file_get_contents($paths->base('.htaccess')))->toBe($rewritten);
});

it('has nothing to do on a site with no forwarder', function (): void {
    expect(repairForwarder(forwarderSite()))->toBe(['repaired' => [], 'failed' => []]);
});

it('reports a file it cannot write instead of throwing', function (): void {
    $paths = forwarderSite();
    file_put_contents($paths->base('.htaccess'), DENYING_HTACCESS);

    $readOnly = new class extends Filesystem
    {
        public function dumpFile(string $filename, $content): void
        {
            throw new IOException('Permission denied');
        }
    };

    expect((new RootForwarderRepair($readOnly, $paths))->repair())->toBe(['repaired' => [], 'failed' => ['.htaccess']])
        ->and((string) file_get_contents($paths->base('.htaccess')))->toBe(DENYING_HTACCESS);
});
