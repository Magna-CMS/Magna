<?php

declare(strict_types=1);

namespace Magna\Media;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Magna\Media\Console\MediaReconvertCommand;
use Magna\Media\Http\Controllers\MediaServeController;
use Magna\Media\Livewire\MediaPickerModal;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConversionPresetRegistry::class, function (): ConversionPresetRegistry {
            $registry = new ConversionPresetRegistry;
            $registry->register(new ConversionPreset('thumb', 150, 150, fit: true));
            $registry->register(new ConversionPreset('card', 600, 400, fit: true));
            $registry->register(new ConversionPreset('hero', 1920, 1080, fit: false));

            return $registry;
        });

        $this->app->singleton(MediaIngestor::class, function (Application $app): MediaIngestor {
            $diskConfig = config('magna.media.disk', 'public');

            return new MediaIngestor(
                $app->make(ConversionPresetRegistry::class),
                is_string($diskConfig) ? $diskConfig : 'public',
            );
        });

        $this->app->singleton(MediaUrlResolver::class);
    }

    public function boot(): void
    {
        // Views for the global media picker (magna::livewire.media-picker-modal).
        $this->loadViewsFrom(__DIR__.'/views', 'magna');

        // Global reusable media picker — available in every Filament page and plugin.
        Livewire::component('magna-media-picker', MediaPickerModal::class);

        // Signed-URL delivery for private (non-S3) disks.
        // SVGs are forced to download inside the controller regardless of disk.
        // SubstituteBindings is attached explicitly: these routes are declared
        // outside the `web`/`api` groups (they need neither a session nor CSRF),
        // and without it the {media} placeholder is never resolved to a model —
        // the controller receives an empty Media instance and the raw ULID as a
        // stray argument, so nothing is ever actually served.
        Route::get('/_media/{media}', MediaServeController::class)
            ->middleware(['signed', SubstituteBindings::class])
            ->name('magna.media.serve');

        // Public (unsigned) route used exclusively for SVGs on public disks.
        // SVG media IDs are ULIDs — 128-bit random identifiers — so enumeration
        // is not a practical concern. The controller adds Content-Disposition:
        // attachment so browsers cannot render SVGs inline from our origin.
        Route::get('/_media/pub/{media}', MediaServeController::class)
            ->middleware(SubstituteBindings::class)
            ->name('magna.media.serve.public');

        if ($this->app->runningInConsole()) {
            $this->commands([MediaReconvertCommand::class]);
        }
    }
}
