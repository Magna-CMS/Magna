<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3).'/bin/support/release-manifest.php';

use Magna\Updater\CoreUpdater;
use Magna\Updater\Engine\PathGuard;
use Magna\Updater\Manifest\InvalidReleaseManifestException;
use Magna\Updater\Manifest\ManifestRules;
use Magna\Updater\Manifest\ReleaseManifest;
use Magna\Updater\UpdateMode;

/**
 * The release describes itself and the updater believes the description —
 * within the bounds of what the updater's own guard allows. Every rule here
 * is a way a build mistake or a stale updater could otherwise hurt a site.
 */
function builtManifest(array $overrides = []): array
{
    $data = release_manifest(
        '99.0.0',
        CoreUpdater::coreOwnedPaths(),
        ['Magna\\Admin\\Pages\\GeneralSettingsPage'],
        'v1.2.0',
        123456,
        ['commit' => 'abc1234', 'tag' => 'v99.0.0'],
        '2026-09-24T00:00:00+00:00',
    );

    foreach ($overrides as $key => $value) {
        if (is_array($value) && isset($data[$key]) && is_array($data[$key]) && ! array_is_list($value)) {
            $data[$key] = array_replace($data[$key], $value);
        } else {
            $data[$key] = $value;
        }
    }

    return $data;
}

it('describes the engine that ships, with the hash the updater will check', function (): void {
    $engine = release_engine(dirname(__DIR__, 3));

    expect($engine['api'])->toBe(1)
        ->and($engine['path'])->toBe('bootstrap/update/engine.php')
        ->and($engine['sha256'])->toBe(hash_file('sha256', dirname(__DIR__, 3).'/bootstrap/update/engine.php'));

    $data = builtManifest(['engine' => $engine]);
    $manifest = ReleaseManifest::fromArray($data, new PathGuard);

    expect(ManifestRules::validate($data, new PathGuard))->toBe([])
        ->and($manifest->hasEngine())->toBeTrue()
        ->and($manifest->engineApi)->toBe(1);
});

it('refuses to describe an engine that is missing or unacceptable', function (): void {
    $stage = sys_get_temp_dir().'/magna-engine-stage-'.bin2hex(random_bytes(4));
    mkdir($stage.'/bootstrap/update', 0777, true);

    try {
        expect(fn () => release_engine($stage))->toThrow(RuntimeException::class, 'missing');

        file_put_contents($stage.'/bootstrap/update/engine.php', "<?php\nclass NotAnEngine {}\n");

        expect(fn () => release_engine($stage))->toThrow(RuntimeException::class, 'declares a class');
    } finally {
        @unlink($stage.'/bootstrap/update/engine.php');
        @rmdir($stage.'/bootstrap/update');
        @rmdir($stage.'/bootstrap');
        @rmdir($stage);
    }
});

it('writes a manifest that passes the rules the updater applies', function (): void {
    $data = builtManifest();

    expect(ManifestRules::validate($data, new PathGuard))->toBe([]);

    $manifest = ReleaseManifest::fromArray($data, new PathGuard, (string) json_encode($data));

    expect($manifest->version)->toBe('99.0.0')
        ->and($manifest->requiresPhp)->toBe('>='.RELEASE_PHP_FLOOR)
        ->and($manifest->minUpgradeFrom)->toBe(RELEASE_MIN_UPGRADE_FROM)
        ->and($manifest->coreOwnedPaths)->toBe(CoreUpdater::coreOwnedPaths())
        ->and($manifest->optionalPaths)->toContain(CoreUpdater::SDK_SOURCE_PATH)
        ->and($manifest->removedClasses)->toBe(['Magna\\Admin\\Pages\\GeneralSettingsPage'])
        ->and($manifest->requiresExtensions)->toContain('zip')
        ->and($manifest->uncompressedBytes)->toBe(123456)
        ->and($manifest->hasEngine())->toBeFalse()
        ->and($manifest->sha256)->toMatch('/^[a-f0-9]{64}$/');
});

it('refuses a schema this updater does not read', function (): void {
    $problems = ManifestRules::validate(builtManifest(['schema' => 2]), new PathGuard);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('newer updater');
});

it('refuses a path outside anything an update may own', function (): void {
    $data = builtManifest(['paths' => ['core_owned' => ['src/Magna', 'resources/views']]]);

    $problems = ManifestRules::validate($data, new PathGuard);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('resources/views')
        ->and($problems[0])->toContain('not under any path');
});

it('refuses a site-owned path even under an allowed prefix', function (): void {
    $data = builtManifest(['paths' => ['core_owned' => ['bootstrap', 'bootstrap/cache']]]);

    $problems = ManifestRules::validate($data, new PathGuard);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('site-owned');
});

it('lets the guard resolve nested ownership by the longest match', function (): void {
    $guard = new PathGuard;

    // Core-owned islands inside site-owned directories, and the reverse.
    expect($guard->isCoreOwnable('config/defaults'))->toBeTrue()
        ->and($guard->isCoreOwnable('config/defaults/magna.php'))->toBeTrue()
        ->and($guard->isCoreOwnable('config'))->toBeFalse()
        ->and($guard->isCoreOwnable('config/magna.php'))->toBeFalse()
        ->and($guard->isCoreOwnable('vendor/magna-cms/plugin-sdk/src'))->toBeTrue()
        ->and($guard->isCoreOwnable('vendor/laravel/framework'))->toBeFalse()
        ->and($guard->isCoreOwnable('bootstrap/app.php'))->toBeTrue()
        ->and($guard->isCoreOwnable('bootstrap/cache/config.php'))->toBeFalse()
        ->and($guard->isCoreOwnable('storage'))->toBeFalse()
        ->and($guard->isCoreOwnable('.env'))->toBeFalse()
        ->and($guard->isCoreOwnable('application'))->toBeFalse();
});

it('refuses paths that could leave the install', function (): void {
    $guard = new PathGuard;

    foreach (['../src/Magna', '/etc/passwd', 'C:/Windows', 'src\\Magna', "src/Magna\0", '', 'src/Magna/../../.env'] as $bad) {
        expect(PathGuard::normalize($bad))->toBeNull()
            ->and($guard->reject($bad))->not->toBeNull();
    }

    expect(PathGuard::normalize('./src//Magna/'))->toBe('src/Magna');
});

it('refuses an engine that does not live under bootstrap/update', function (): void {
    $data = builtManifest(['engine' => ['api' => 1, 'path' => 'src/Magna/engine.php', 'sha256' => str_repeat('a', 64)]]);

    $problems = ManifestRules::validate($data, new PathGuard);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('bootstrap/update');
});

it('refuses a manifest whose version is not the one announced', function (): void {
    $manifest = ReleaseManifest::fromArray(builtManifest(), new PathGuard);

    expect($manifest->announcedAs('99.0.0'))->toBeNull()
        ->and($manifest->announcedAs('v99.0.0'))->toBeNull()
        ->and($manifest->announcedAs('99.0.1'))->toContain('was announced');
});

it('names every requirement the host does not meet', function (): void {
    $manifest = ReleaseManifest::fromArray(builtManifest([
        'requires' => ['php' => '>=99.0.0', 'extensions' => ['pdo', 'no_such_extension'], 'min_upgrade_from' => '50.0.0'],
    ]), new PathGuard);

    $unmet = $manifest->unmetRequirements('8.3.0', extension_loaded(...), '1.4.4', UpdateMode::Update);

    expect($unmet)->toHaveCount(3)
        ->and($unmet[0])->toContain('needs PHP >=99.0.0')
        ->and($unmet[1])->toContain('no_such_extension')
        ->and($unmet[2])->toContain('v50.0.0 or newer');

    // A repair re-applies the installed release; how old the site was before
    // it got there is not the question.
    expect($manifest->unmetRequirements('99.0.0', static fn (): bool => true, '1.4.4', UpdateMode::Repair))->toBe([]);
});

it('throws with every problem when the manifest is unusable', function (): void {
    $data = builtManifest(['product' => 'something-else', 'paths' => ['core_owned' => ['storage']]]);

    try {
        ReleaseManifest::fromArray($data, new PathGuard);
        test()->fail('An unusable manifest was accepted.');
    } catch (InvalidReleaseManifestException $e) {
        expect($e->problems)->toHaveCount(2)
            ->and($e->getMessage())->toContain('storage');
    }
});

it('reads no manifest from an archive that predates them', function (): void {
    $dir = sys_get_temp_dir().'/magna-manifest-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    try {
        expect(ReleaseManifest::fromExtractedArchive($dir, new PathGuard))->toBeNull();

        file_put_contents($dir.'/'.ReleaseManifest::FILENAME, '{not json');

        expect(fn () => ReleaseManifest::fromExtractedArchive($dir, new PathGuard))
            ->toThrow(InvalidReleaseManifestException::class, 'not valid JSON');
    } finally {
        @unlink($dir.'/'.ReleaseManifest::FILENAME);
        @rmdir($dir);
    }
});

it('lists the required paths an archive does not contain', function (): void {
    $dir = sys_get_temp_dir().'/magna-manifest-'.bin2hex(random_bytes(4));
    mkdir($dir.'/src/Magna', 0777, true);

    $manifest = ReleaseManifest::fromArray(builtManifest([
        'paths' => ['core_owned' => ['src/Magna', 'app', 'bundled/magna-cms/plugin-sdk'], 'optional' => ['bundled/magna-cms/plugin-sdk']],
    ]), new PathGuard);

    try {
        expect($manifest->missingFrom($dir))->toBe(['app']);
    } finally {
        @rmdir($dir.'/src/Magna');
        @rmdir($dir.'/src');
        @rmdir($dir);
    }
});
