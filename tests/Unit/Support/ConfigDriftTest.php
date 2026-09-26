<?php

declare(strict_types=1);

use Magna\Support\ConfigDrift;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

// Core's defaults file calls storage_path()/base_path(), so loading it
// through ConfigDrift needs a booted application, not the bare container.
uses(TestCase::class);

/**
 * Drift is reported, never repaired: a key the site file defines is the
 * site's. What the operator needs is to see which keys core is quietly
 * supplying and which of their own settings nothing reads any more.
 */
function driftSite(?string $configSource): string
{
    $base = sys_get_temp_dir().'/magna-drift-'.bin2hex(random_bytes(4));
    mkdir($base.'/config', 0777, true);

    if ($configSource !== null) {
        file_put_contents($base.'/config/magna.php', $configSource);
    }

    return $base;
}

it('reports a forwarding site file as customising nothing', function (): void {
    $base = driftSite("<?php\n\nreturn require '".str_replace('\\', '/', dirname(__DIR__, 3))."/src/Magna/Config/defaults/magna.php';\n");

    try {
        $report = (new ConfigDrift($base))->report();

        expect($report['site_file_is_forwarder'])->toBeTrue()
            ->and($report['missing'])->toBe([])
            ->and($report['tombstones_set'])->toBe([]);
    } finally {
        (new Filesystem)->remove($base);
    }
});

it('lists the keys a customised site file lacks and the replaced keys it still sets', function (): void {
    $base = driftSite("<?php\n\nreturn ['login' => ['max_attempts' => 3], 'updater' => ['require_signed_checksum' => false]];\n");

    try {
        $report = (new ConfigDrift($base))->report();

        expect($report['site_file_is_forwarder'])->toBeFalse()
            ->and($report['missing'])->toContain('magna.security.trusted_hosts')
            ->and($report['missing'])->toContain('magna.login.base_lockout_seconds')
            ->and($report['missing'])->not->toContain('magna.login.max_attempts')
            ->and($report['tombstones_set'])->toBe(['magna.updater.require_signed_checksum']);
    } finally {
        (new Filesystem)->remove($base);
    }
});

it('does not count a tombstone the site left as null', function (): void {
    $base = driftSite("<?php\n\nreturn ['updater' => ['require_signed_checksum' => null]];\n");

    try {
        expect((new ConfigDrift($base))->report()['tombstones_set'])->toBe([]);
    } finally {
        (new Filesystem)->remove($base);
    }
});

it('treats a missing site file as supplying nothing', function (): void {
    $base = driftSite(null);

    try {
        $report = (new ConfigDrift($base))->report();

        expect($report['site_file_is_forwarder'])->toBeFalse()
            ->and($report['missing'])->toContain('magna.media.disk');
    } finally {
        (new Filesystem)->remove($base);
    }
});
