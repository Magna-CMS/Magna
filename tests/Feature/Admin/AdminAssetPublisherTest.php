<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Magna\Admin\AdminAssetPublisher;
use Magna\Plugins\PluginManager;
use Magna\Updater\RuntimeRefresh;
use Tests\TestCase;

/*
 * Panel assets — Filament's own and every plugin's — are only served once
 * `filament:assets` copies them into public/. Nothing ran it after a core
 * update or a plugin enable: a marketplace-installed blog's editor script
 * 404'd and its post editor showed no body. Requests are deferred to the end
 * of the request, when every enabled plugin has registered its assets.
 */

uses(TestCase::class, RefreshDatabase::class);

/** A real publisher whose copy is counted instead of run. */
function countingPublisher(?Throwable $failWith = null): AdminAssetPublisher
{
    $publisher = new class extends AdminAssetPublisher
    {
        public int $runs = 0;

        public ?Throwable $failWith = null;

        public function publish(): void
        {
            $this->runs++;

            if ($this->failWith !== null) {
                throw $this->failWith;
            }
        }
    };
    $publisher->failWith = $failWith;

    return $publisher;
}

beforeEach(function (): void {
    @unlink(storage_path('framework/magna-admin-assets.stale'));
});

afterEach(function (): void {
    @unlink(storage_path('framework/magna-admin-assets.stale'));
});

it('publishes once per request, and only when asked', function (): void {
    $publisher = countingPublisher();

    $publisher->publishIfRequested();
    expect($publisher->runs)->toBe(0);

    $publisher->requestPublish();
    $publisher->publishIfRequested();
    $publisher->publishIfRequested();

    expect($publisher->runs)->toBe(1)
        ->and(is_file(storage_path('framework/magna-admin-assets.stale')))->toBeFalse();
});

// A plugin shipping a missing asset makes the copy fail every time; retrying
// on every request would turn one broken file into a slow site.
it('logs a failed copy once and never throws', function (): void {
    Log::spy();
    $publisher = countingPublisher(new RuntimeException('File does not exist at path dist/editor.js'));

    $publisher->requestPublish();
    $publisher->publishIfRequested();
    $publisher->publishIfRequested();

    expect($publisher->runs)->toBe(1);
    Log::shouldHaveReceived('warning')->once();
});

it('asks for a publish after a core update clears its caches', function (): void {
    $publisher = countingPublisher();
    app()->instance(AdminAssetPublisher::class, $publisher);

    app(RuntimeRefresh::class)->clearCaches();

    // Requested, not run: the finalizer boots before plugins do.
    expect($publisher->runs)->toBe(0)
        ->and(is_file(storage_path('framework/magna-admin-assets.stale')))->toBeTrue();
});

it('asks for a publish whenever a plugin is enabled', function (): void {
    skipWithoutDevPlugin('magna-cms/docs');
    app()->instance(AdminAssetPublisher::class, countingPublisher());

    app(PluginManager::class)->enable('magna-cms/docs');

    expect(is_file(storage_path('framework/magna-admin-assets.stale')))->toBeTrue();
});

it('honours a pending request when the application terminates', function (): void {
    $publisher = countingPublisher();
    app()->instance(AdminAssetPublisher::class, $publisher);
    $publisher->requestPublish();

    app()->terminate();

    expect($publisher->runs)->toBe(1);
});
