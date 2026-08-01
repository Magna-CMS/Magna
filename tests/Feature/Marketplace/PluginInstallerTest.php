<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Magna\Marketplace\ComposerRunner;
use Magna\Marketplace\InstallState;
use Magna\Marketplace\Marketplace;
use Magna\Marketplace\PluginInstaller;
use Magna\Plugins\PluginRecord;
use Tests\Support\FakeComposerRunner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function fakeRunner(): FakeComposerRunner
{
    $runner = new FakeComposerRunner;
    app()->instance(ComposerRunner::class, $runner);

    return $runner;
}

/** @param list<array<string, mixed>> $catalog */
function fakeMarket(array $catalog): void
{
    Http::fake([Marketplace::API_BASE.'/*' => Http::response($catalog)]);
}

beforeEach(function (): void {
    Cache::flush();
});

it('installs and enables an approved plugin', function (): void {
    // enable() resolves the real discovered plugin, so this one needs it present.
    skipWithoutDevPlugin('magna/docs');

    // Read the version off the plugin rather than pinning a literal:
    // verifyInstalledManifest() compares the approved version against the
    // manifest actually on disk, so a hardcoded one fails the install the day
    // the plugin ships a release.
    $version = devPluginVersion('magna/docs');

    fakeMarket([['package' => 'magna/docs', 'name' => 'Magna Docs', 'version' => $version, 'compat' => '^1.0']]);
    $runner = fakeRunner();

    $state = app(PluginInstaller::class)->install('magna/docs');

    expect($state)->toBe(InstallState::Completed)
        // Stage 6: pinned to the exact approved version, not an unpinned "latest".
        ->and($runner->commands)->toContain(['require', 'magna/docs:'.$version])
        ->and(PluginRecord::query()->where('name', 'magna/docs')->where('enabled', true)->exists())->toBeTrue();
});

it('refuses a package that is not in the marketplace', function (): void {
    fakeMarket([]); // empty catalog + no detail
    Http::fake([Marketplace::API_BASE.'/*' => Http::response('', 404)]);
    $runner = fakeRunner();

    $state = app(PluginInstaller::class)->install('evil/backdoor');

    expect($state)->toBe(InstallState::Failed)
        ->and($runner->commands)->toBe([]); // Composer never ran
});

it('fails cleanly when composer cannot install', function (): void {
    fakeMarket([['package' => 'acme/thing', 'name' => 'Thing', 'version' => '1.0.0', 'compat' => '^1.0']]);
    $runner = fakeRunner();
    $runner->exitCode = 1;
    $runner->output = 'Could not resolve dependencies.';

    $state = app(PluginInstaller::class)->install('acme/thing');

    expect($state)->toBe(InstallState::Failed)
        ->and(PluginInstaller::progress('acme/thing')['message'])->toContain('Composer');
});

it('rolls back when the plugin installs but fails to enable', function (): void {
    // acme/ghost is "approved" and composer "succeeds", but it isn't really
    // installed, so PluginManager::enable() throws → rollback.
    fakeMarket([['package' => 'acme/ghost', 'name' => 'Ghost', 'version' => '1.0.0', 'compat' => '^1.0']]);
    $runner = fakeRunner();

    $state = app(PluginInstaller::class)->install('acme/ghost');

    expect($state)->toBe(InstallState::Failed)
        ->and($runner->commands)->toContain(['require', 'acme/ghost:1.0.0'])
        ->and($runner->commands)->toContain(['remove', 'acme/ghost']); // rolled back
});

it('rolls back when the installed manifest version does not match the approved version', function (): void {
    // magna/docs is a real, discoverable dev plugin whose manifest version
    // is 1.0.0 — claim the marketplace approved a different version to
    // simulate Composer resolving something other than what was reviewed.
    fakeMarket([['package' => 'magna/docs', 'name' => 'Magna Docs', 'version' => '9.9.9', 'compat' => '^1.0']]);
    $runner = fakeRunner();

    $state = app(PluginInstaller::class)->install('magna/docs');

    expect($state)->toBe(InstallState::Failed)
        ->and($runner->commands)->toContain(['require', 'magna/docs:9.9.9'])
        ->and($runner->commands)->toContain(['remove', 'magna/docs'])
        ->and(PluginInstaller::progress('magna/docs')['message'])->toContain('did not match the approved version');
});

it('fails when composer is unavailable on the host', function (): void {
    fakeMarket([['package' => 'acme/thing', 'name' => 'Thing', 'version' => '1.0.0', 'compat' => '^1.0']]);
    $runner = fakeRunner();
    $runner->available = false;

    $state = app(PluginInstaller::class)->install('acme/thing');

    expect($state)->toBe(InstallState::Failed)
        ->and($runner->commands)->toBe([]);
});
