<?php

declare(strict_types=1);

namespace Magna\Admin\Concerns;

/**
 * A full browser reload from a Livewire component, done once and done right.
 *
 * Some panel actions change what the CURRENT page is made of — a plugin was
 * enabled and its navigation exists now, a theme took over, the Account
 * connected — and Livewire's partial re-render cannot pick that up, so the
 * page replaces its own location. The delay exists so the notification
 * dispatched just before the reload is actually seen; json_encode() is the
 * escaping (the URL travels into a JS string).
 *
 * The same setTimeout line used to be pasted eleven times across three
 * classes with three different magic delays.
 */
trait ReloadsBrowser
{
    /** Milliseconds a just-sent notification needs to register before the reload. */
    private const RELOAD_DELAY_MS = 600;

    protected function replaceUrl(string $url, int $delayMs = self::RELOAD_DELAY_MS): void
    {
        $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, '.$delayMs.')');
    }
}
