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

        $this->app->singleton(PageTreeValidator::class, function (): PageTreeValidator {
            return new PageTreeValidator(app(BlockRegistry::class));
        });

        $this->app->singleton(PageTreeAuthorizer::class);

        // Plugin data feeds for the Pages Loop block (RegistersDataSources).
        $this->app->singleton(DataSources\DataSourceRegistry::class);

        // Plugin dynamic tags for page bindings (RegistersDynamicTags).
        $this->app->singleton(DynamicTags\DynamicTagRegistry::class);

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
