<?php

declare(strict_types=1);

namespace Magna\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies every registered Filament asset out of vendor/ into public/.
 *
 * Filament serves panel scripts and styles — its own, and every plugin's
 * registered with FilamentAsset — only once `filament:assets` has copied
 * them. Nothing ran it after a plugin was installed, updated or enabled, or
 * after a core update replaced vendor/filament: the blog's editor script
 * 404'd and its post editor showed a title and no body, and a core release
 * could leave Filament's own JavaScript a version behind its PHP.
 *
 * Two ways in. `publish()` runs now — for a caller that has the plugins it
 * needs booted in this process (the marketplace installer, which runs in a
 * queue worker where terminate callbacks never fire). `requestPublish()`
 * only leaves a marker, for callers that run too early for that: the core
 * update finalizer runs during provider boot, before plugins register their
 * assets. AdminServiceProvider honours the marker when the request
 * terminates, by which time every enabled plugin has booted.
 *
 * Its own class so tests can stub it — the real command rewrites tracked
 * files under public/ in whatever checkout runs the suite.
 */
class AdminAssetPublisher
{
    public function publish(): void
    {
        Artisan::call('filament:assets');
    }

    public function requestPublish(): void
    {
        try {
            @touch($this->marker());
        } catch (Throwable) {
            // An unwritable storage/ is a stale asset, not a failed update.
        }
    }

    /**
     * Publish if something asked for it. The marker is removed BEFORE the
     * copy: a copy that keeps failing (a plugin shipping a missing file)
     * must not re-run on every request, so a failure is logged once and
     * waits for the next request to publish.
     */
    public function publishIfRequested(): void
    {
        $marker = $this->marker();
        if (! is_file($marker)) {
            return;
        }

        @unlink($marker);

        try {
            $this->publish();
        } catch (Throwable $e) {
            Log::warning('Admin assets could not be published.', ['reason' => $e->getMessage()]);
        }
    }

    private function marker(): string
    {
        return storage_path('framework/magna-admin-assets.stale');
    }
}
