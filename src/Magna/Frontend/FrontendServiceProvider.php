<?php

declare(strict_types=1);

namespace Magna\Frontend;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Magna\Frontend\Forms\FormTokenController;

/**
 * The seams the public site's renderers share: what the request asks for
 * (RenderContext), what the rendered content says about caching it
 * (CacheCollector), how locale prefixes map onto URLs (LocaleUrls), and
 * the CSRF token endpoint that lets a form live on a cached page.
 *
 * Both per-request objects are scoped, not singletons: under Octane or a
 * queue worker one container serves many requests, and a page number or a
 * cache veto leaking into the next request would be a wrong page or an
 * uncacheable site.
 */
final class FrontendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocaleUrls::class);
        $this->app->scoped(RenderContext::class);
        $this->app->scoped(CacheCollector::class);
    }

    public function boot(): void
    {
        // magna::partials.pagination and <x-magna::form-token/>, beside the
        // block views that already live under the same namespace.
        $this->loadViewsFrom(__DIR__.'/resources/views', 'magna');

        Route::middleware('web')
            ->get('/_magna/form-token', FormTokenController::class)
            ->name('magna.form-token');
    }
}
