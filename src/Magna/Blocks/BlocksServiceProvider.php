<?php

declare(strict_types=1);

namespace Magna\Blocks;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Magna\Blocks\Http\Controllers\BlockPreviewController;
use Magna\Blocks\Livewire\BlockEditor;

class BlocksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BlockRegistry::class, function (): BlockRegistry {
            $registry = new BlockRegistry;

            // Load the 19 core block definitions from their JSON schemas
            $registry->loadFromDirectory(__DIR__.'/blocks');

            return $registry;
        });

        /*
         * The icon vocabulary. A singleton like the block registry and for
         * the same reason: plugins add to it at boot, and everything that
         * draws an icon must be looking at the same list.
         */
        $this->app->singleton(Icons\IconRegistry::class, function (): Icons\IconRegistry {
            $registry = new Icons\IconRegistry;
            $registry->loadFromDirectory(__DIR__.'/resources/icons');

            return $registry;
        });

        $this->app->singleton(PageTreeValidator::class, function (): PageTreeValidator {
            return new PageTreeValidator(app(BlockRegistry::class));
        });

        $this->app->singleton(PageTreeAuthorizer::class);

        // Plugin data feeds for the Pages Loop block (RegistersDataSources).
        $this->app->singleton(DataSources\DataSourceRegistry::class);

        // Plugin dynamic tags for page bindings (RegistersDynamicTags).
        $this->app->singleton(DynamicTags\DynamicTagRegistry::class);

        /*
         * Plugin show/hide rules for page nodes (RegistersDisplayConditions).
         *
         * A singleton, like the two registries above and for the same reason:
         * plugins fill it once at enable-time, and a fresh instance per
         * resolve would be empty — which fails closed, so every conditioned
         * node would quietly vanish rather than error.
         */
        $this->app->singleton(Conditions\DisplayConditionRegistry::class);

        /*
         * What one render may spend on plugin resolvers.
         *
         * `scoped` rather than `singleton` on purpose: under Octane the
         * container survives the request, and a singleton would carry one
         * page's spending into the next — refusing resolvers on a page that
         * had asked for nothing yet.
         */
        $this->app->scoped(Resolution\ResolverBudget::class, function (): Resolution\ResolverBudget {
            $budget = config('magna.render_budget');
            $budget = is_array($budget) ? $budget : [];

            return new Resolution\ResolverBudget(
                maxInvocations: is_numeric($budget['max_resolvers'] ?? null) ? (int) $budget['max_resolvers'] : 200,
                maxMilliseconds: is_numeric($budget['max_milliseconds'] ?? null) ? (int) $budget['max_milliseconds'] : 750,
                slowMilliseconds: is_numeric($budget['slow_milliseconds'] ?? null) ? (int) $budget['slow_milliseconds'] : 250,
            );
        });

        $this->app->singleton(Resolution\BlockDataResolver::class, function (): Resolution\BlockDataResolver {
            $resolver = new Resolution\BlockDataResolver;

            // Core dynamic-block resolvers. Plugins get their own
            // registration surface with the Pages RegistersDataSources
            // contract; until then this is the single registration point.
            $resolver->register(app(Resolution\EntriesBlockResolver::class));
            $resolver->register(app(Resolution\TextBlockResolver::class));

            return $resolver;
        });
    }

    public function boot(): void
    {
        // Register default block Blade views under the magna:: namespace.
        // Both the block views (magna::blocks.*) and the block editor view
        // (magna::block-editor.editor) and preview (magna::block-preview.preview)
        // live under this same directory tree.
        $this->loadViewsFrom(__DIR__.'/resources/views', 'magna');

        // Register the Livewire block editor component
        Livewire::component('magna-block-editor', BlockEditor::class);

        // Preview endpoint: POST /magna-preview/blocks (admin-auth required)
        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::post('/magna-preview/blocks', BlockPreviewController::class)
                ->name('magna.blocks.preview');
        });
    }
}
