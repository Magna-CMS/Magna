<?php

declare(strict_types=1);

use Magna\Admin\AdminAssetPublisher;
use Magna\Plugins\PluginCacheInvalidator;
use Magna\Updater\RuntimeRefresh;
use Tests\TestCase;

uses(TestCase::class);

/**
 * afterFilesChanged() is the one entry point for "this plugin's files were
 * just replaced on disk": the runtime refresh a core update gets, plus an
 * inline asset publish — all best-effort, because a caching failure must
 * never fail the update that asked for it.
 */
beforeEach(function (): void {
    $this->publisher = new class extends AdminAssetPublisher
    {
        public int $published = 0;

        public int $requested = 0;

        public function publish(): void
        {
            $this->published++;
        }

        public function requestPublish(): void
        {
            $this->requested++;
        }
    };

    $this->app->instance(AdminAssetPublisher::class, $this->publisher);
});

it('runs the full runtime refresh and publishes assets inline', function (): void {
    $this->app->make(PluginCacheInvalidator::class)->afterFilesChanged();

    // RuntimeRefresh::clearCaches() requests a deferred publish; the inline
    // publish is afterFilesChanged's own last step.
    expect($this->publisher->published)->toBe(1)
        ->and($this->publisher->requested)->toBeGreaterThanOrEqual(1);
});

it('does not let a failing refresh block the operation', function (): void {
    $this->app->bind(RuntimeRefresh::class, function (): never {
        throw new RuntimeException('no console kernel here');
    });

    $this->app->make(PluginCacheInvalidator::class)->afterFilesChanged();

    // Still reached the asset publish, and nothing propagated.
    expect($this->publisher->published)->toBe(1);
});
