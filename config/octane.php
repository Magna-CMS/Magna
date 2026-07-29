<?php

use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;
use Magna\Content\FieldTypeRegistry;

return [

    /*
    |--------------------------------------------------------------------------
    | Octane Server
    |--------------------------------------------------------------------------
    | Reference deployment: FrankenPHP worker mode.
    | Install: composer require laravel/octane
    | Start:   php artisan octane:frankenphp
    */

    'server' => env('OCTANE_SERVER', 'frankenphp'),

    /*
    |--------------------------------------------------------------------------
    | HTTPS / TLS (FrankenPHP)
    |--------------------------------------------------------------------------
    | Set OCTANE_HTTPS=false in local dev; FrankenPHP manages TLS in production.
    */

    'https' => (bool) env('OCTANE_HTTPS', false),

    /*
    |--------------------------------------------------------------------------
    | Worker Count
    |--------------------------------------------------------------------------
    | 0 = auto (one worker per CPU core).
    */

    'workers' => (int) env('OCTANE_WORKERS', 0),

    'max_requests' => (int) env('OCTANE_MAX_REQUESTS', 1000),

    /*
    |--------------------------------------------------------------------------
    | Octane Listeners
    |--------------------------------------------------------------------------
    | These are Octane's stock per-request/per-worker state-reset listeners.
    | They MUST stay populated: an empty list disables Octane's own cleanup
    | (uploaded-file validation, temporary container-instance flushing) and,
    | because prepareApplicationForNextRequest() is what applies the `warm`/
    | `flush` bindings below, an empty list would silently make those two lists
    | do nothing between requests. This is the framework default, kept verbatim.
    */

    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
        ],

        RequestHandled::class => [
            //
        ],

        RequestTerminated::class => [
            // FlushUploadedFiles::class,
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TickTerminated::class => [
            //
        ],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
            // DisconnectFromDatabases::class,
            // CollectGarbage::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Warm / Flush
    |--------------------------------------------------------------------------
    | `warm` pre-resolves bindings at worker boot; `flush` re-resolves them
    | between requests.
    |
    | warm: only genuinely immutable services. FieldTypeRegistry is built
    | entirely from core, compiled-in field types, so it is safe to warm.
    |
    | flush: intentionally empty. Magna's mutable runtime state does NOT rely on
    | Octane's flush mechanism:
    |   - Settings (Magna\Settings\*Settings) are Octane-safe by construction:
    |     ::get() builds a fresh instance and re-hydrates from the shared
    |     `magna-settings:{group}` cache on every call, and that cache is
    |     Cache::forget()-invalidated whenever a setting is saved — so a value
    |     changed on one worker is visible to all workers on their next read.
    |     They are never bound as singletons, so listing them here would be a
    |     no-op (flush re-resolves container bindings, which ::get() never uses).
    |   - SchemaRegistry is a persistent singleton re-synced from the
    |     cache-backed content_types table each request by
    |     Magna\Content\Http\Middleware\RefreshDatabaseContentTypes. It must NOT
    |     be flushed: a fresh instance would lose the content types loaded at
    |     boot (loadFromDatabase runs at boot, not on resolve).
    |   - BlockRegistry holds core blocks (stable) plus plugin-registered blocks
    |     (programmatic, added during a plugin's boot). Plugin block sets, like
    |     any programmatic plugin state, change only on enable/disable and are
    |     applied to running workers by reloading them (`php artisan octane:reload`)
    |     — the standard Octane model for applying code/state changes. Content
    |     types are the deliberate exception (public-delivery correctness).
    */

    'warm' => [
        FieldTypeRegistry::class,
    ],

    'flush' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Garbage Collection
    |--------------------------------------------------------------------------
    */

    'garbage' => 50,

    /*
    |--------------------------------------------------------------------------
    | Tables (Swoole only — ignored by FrankenPHP)
    |--------------------------------------------------------------------------
    */

    'tables' => [],

];
