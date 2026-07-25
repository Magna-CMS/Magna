<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Magna\Audit\Listeners\RecordLoginFailure;
use Magna\Audit\Listeners\RecordLoginSuccess;
use Magna\Auth\PermissionRegistry;
use Magna\Blocks\BlockRegistry;
use Magna\Content\Models\ContentTypeRecord;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Contracts\DecoratesDeliveryResponse;
use Magna\Contracts\ExtendsEntryForm;
use Magna\Contracts\RegistersAdminNavigation;
use Magna\Contracts\RegistersBlocks;
use Magna\MagnaServiceProvider;
use Magna\Plugins\Exceptions\PluginCompatibilityException;
use Magna\Plugins\Exceptions\PluginNotFoundException;
use Throwable;

class PluginManager
{
    /** @var array<string, Plugin> */
    private array $booted = [];

    public function __construct(
        private readonly Application $app,
        private readonly PluginDiscovery $discovery,
    ) {}

    /**
     * Called from PluginsServiceProvider::boot(). Loads all enabled plugins and
     * runs register() then boot() on each, registering their routes and permissions.
     */
    /**
     * Stage 13 (S1-07): a plugin's register()/boot() runs with the full
     * Application container and Router in scope — nothing in PHP or
     * Laravel stops it from re-aliasing a security-critical middleware
     * name (e.g. `magna.api` → a no-op class, defanging every Management
     * API route) or rebinding a security-critical singleton (e.g.
     * PermissionRegistry, making every permission check pass). This can't
     * be prevented outright without a real sandbox, which PHP doesn't have
     * — but it CAN be detected and reverted every time plugins boot,
     * closing the "silently persists for the rest of the request/process"
     * window even though it can't close the instant-of-tampering window
     * itself. Any detected tamper is logged as critical so it surfaces in
     * monitoring rather than silently taking effect.
     *
     * Stage 13 (S1-09): the same window applies to the login audit trail —
     * a plugin's boot() can call `Event::forget(Login::class)` (or
     * `Failed::class`) to silently unregister RecordLoginSuccess /
     * RecordLoginFailure, so every subsequent login on every guard (the
     * Filament admin panel's own built-in Login page included — it's a
     * separate call site from Magna\Auth\Http\Controllers\LoginController
     * that dispatches the same core Illuminate\Auth\Events\Login/Failed
     * events) goes unaudited with no error or trace. Converting the two
     * listeners into inline calls at "the" login call site doesn't close
     * this for Filament's own login page, which isn't Magna code. Instead
     * this integrity check verifies the listeners are still attached after
     * every plugin-boot pass and re-registers them if not.
     *
     * @var list<string>
     */
    private const PROTECTED_MIDDLEWARE_ALIASES = [
        'magna.api', 'magna.api.key', 'magna.security-headers',
        'magna.admin-csp', 'magna.two-factor', 'magna.two-factor-enrolled',
        'magna.force-https',
    ];

    public function bootEnabledPlugins(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        $integritySnapshot = $this->captureSecurityIntegritySnapshot();

        /** @var Collection<int, PluginRecord> $records */
        $records = PluginRecord::query()->where('enabled', true)->get();

        // First pass — register() on every plugin (so container bindings are in place for boot()).
        // A plugin whose files were deleted after installation must not crash the entire CMS.
        // Auto-disable any plugin that fails here so the admin panel remains accessible.
        foreach ($records as $record) {
            try {
                $plugin = $this->instantiate($record->manifest, $record->base_path);
                $plugin->register();
                $this->booted[$record->name] = $plugin;
            } catch (Throwable $e) {
                $record->update(['enabled' => false, 'disabled_at' => now()]);
                logger()->error("Plugin [{$record->name}] auto-disabled: class or files missing. {$e->getMessage()}");
            }
        }

        // Second pass — boot() once all plugins have had a chance to register.
        foreach ($this->booted as $name => $plugin) {
            try {
                $plugin->boot();
                $this->loadRoutes($plugin);
                $this->registerPermissions($plugin->getManifest());
            } catch (Throwable $e) {
                unset($this->booted[$name]);
                logger()->error("Plugin [{$name}] auto-disabled during boot: {$e->getMessage()}");
            }
        }

        // Dispatch typed contracts.
        $this->dispatchContracts();

        $this->verifySecurityIntegrity($integritySnapshot);
    }

    /**
     * @return array{middleware: array<string, string>, permissionRegistry: PermissionRegistry, loginListenerActive: bool, failedListenerActive: bool}
     */
    private function captureSecurityIntegritySnapshot(): array
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $currentAliases = $router->getMiddleware();

        $middleware = [];
        foreach (self::PROTECTED_MIDDLEWARE_ALIASES as $alias) {
            if (isset($currentAliases[$alias])) {
                $middleware[$alias] = $currentAliases[$alias];
            }
        }

        return [
            'middleware' => $middleware,
            'permissionRegistry' => $this->app->make(PermissionRegistry::class),
            'loginListenerActive' => Event::hasListeners(Login::class),
            'failedListenerActive' => Event::hasListeners(Failed::class),
        ];
    }

    /**
     * @param  array{middleware: array<string, string>, permissionRegistry: PermissionRegistry, loginListenerActive: bool, failedListenerActive: bool}  $snapshot
     */
    private function verifySecurityIntegrity(array $snapshot): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $currentAliases = $router->getMiddleware();

        foreach ($snapshot['middleware'] as $alias => $expectedClass) {
            $actualClass = $currentAliases[$alias] ?? null;
            if ($actualClass !== $expectedClass) {
                Log::critical("A plugin changed the protected middleware alias \"{$alias}\" from {$expectedClass} to ".($actualClass ?? '(removed)').' — reverted.');
                $router->aliasMiddleware($alias, $expectedClass);
            }
        }

        if ($this->app->make(PermissionRegistry::class) !== $snapshot['permissionRegistry']) {
            Log::critical('A plugin rebound the PermissionRegistry singleton — reverted. Every permission check would otherwise have run against the plugin-supplied replacement for the rest of this process.');
            $this->app->instance(PermissionRegistry::class, $snapshot['permissionRegistry']);
        }

        if ($snapshot['loginListenerActive'] && ! Event::hasListeners(Login::class)) {
            Log::critical('A plugin unregistered all Login event listeners (Event::forget) — the login audit trail re-registered. Every successful login between the unregister and this check went unaudited.');
            Event::listen(Login::class, RecordLoginSuccess::class);
        }

        if ($snapshot['failedListenerActive'] && ! Event::hasListeners(Failed::class)) {
            Log::critical('A plugin unregistered all Failed (login) event listeners (Event::forget) — the login audit trail re-registered. Every failed login between the unregister and this check went unaudited.');
            Event::listen(Failed::class, RecordLoginFailure::class);
        }
    }

    /**
     * Validate, install, and enable a plugin.
     *
     * @throws PluginNotFoundException
     * @throws PluginCompatibilityException
     */
    public function enable(string $name): void
    {
        $info = $this->discovery->find($name);
        if ($info === null) {
            throw new PluginNotFoundException($name);
        }

        if (! $info->manifest->isCompatibleWith(MagnaServiceProvider::VERSION)) {
            throw new PluginCompatibilityException(
                "Plugin [{$name}] requires magna {$info->manifest->magnaCompat} "
                .'but the installed core is '.MagnaServiceProvider::VERSION.'.'
            );
        }

        $this->runMigrations($info->basePath);

        // Stage 11 (S11-03): the PluginRecord write, permission
        // registration, and content-type persistence are dependent DML
        // steps — if the last one (persistPluginContentTypes) throws, the
        // earlier ones were previously left committed, so the plugin would
        // show as "enabled" in the admin list while missing the content
        // types it's supposed to provide, with no clean way to recover
        // short of manual DB surgery. runMigrations() is DDL and stays
        // outside — MySQL auto-commits DDL regardless, so wrapping it
        // would only be misleading (same caveat already accepted in
        // SchemaSyncer for the same reason).
        $plugin = DB::transaction(function () use ($name, $info): Plugin {
            $record = PluginRecord::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $info->manifest->displayName,
                    'version' => $info->manifest->version,
                    'enabled' => true,
                    'base_path' => $info->basePath,
                    'enabled_at' => now(),
                    'disabled_at' => null,
                    'manifest' => $info->manifest->toArray(),
                ]
            );

            $plugin = $this->instantiate($record->manifest, $record->base_path);
            $this->activatePlugin($plugin, $info->manifest);

            return $plugin;
        });

        $this->booted[$name] = $plugin;

        // Enabling a plugin adds resources/pages/widgets to the admin panel. If the
        // Filament component cache is warm it would hide them, so invalidate it.
        $this->invalidateAdminPanelCache();
    }

    /**
     * Disable a plugin (data is preserved; no DB drops).
     *
     * @throws PluginNotFoundException
     */
    public function disable(string $name): void
    {
        /** @var PluginRecord|null $record */
        $record = PluginRecord::query()->where('name', $name)->first();
        if ($record === null) {
            throw new PluginNotFoundException($name);
        }

        if (isset($this->booted[$name])) {
            $this->booted[$name]->disable();
            unset($this->booted[$name]);
        } else {
            $plugin = $this->instantiate($record->manifest, $record->base_path);
            $plugin->disable();
        }

        // A disabled plugin must not leave its content types active — otherwise
        // SchemaRegistry::loadFromDatabase() keeps re-registering them and their
        // admin navigation lingers. Data tables are preserved (disable never
        // destroys data); re-enable restores the content_types records.
        $this->deregisterContentTypes($record, dropTables: false);

        $record->update(['enabled' => false, 'disabled_at' => now()]);

        // Its admin resources/pages/widgets are gone now — drop any stale panel cache.
        $this->invalidateAdminPanelCache();
    }

    /**
     * Disable and remove a plugin's DB record. With --purge, also drops its declared tables.
     *
     * @throws PluginNotFoundException
     */
    public function uninstall(string $name, bool $purge = false): void
    {
        /** @var PluginRecord|null $record */
        $record = PluginRecord::query()->where('name', $name)->first();
        if ($record === null) {
            throw new PluginNotFoundException($name);
        }

        if ($record->enabled) {
            $this->disable($name);
        }

        // Remove the plugin's content types. Without --purge the entries data
        // tables are kept (data preserved); with --purge they are dropped along
        // with any tables the manifest declares.
        // Stage 11 (S11-03): deregisterContentTypes (DML) and the final
        // record delete (DML) wrapped together — if the delete failed
        // after purge() already dropped tables (DDL, can't be transactional
        // on MySQL either way), the PluginRecord would otherwise survive
        // pointing at tables that no longer exist.
        DB::transaction(function () use ($record, $purge): void {
            $this->deregisterContentTypes($record, dropTables: $purge);

            if ($purge) {
                $this->purge($record);
            }

            $record->delete();
        });

        $this->invalidateAdminPanelCache();
    }

    /**
     * Runs the full "bring a plugin instance to life" sequence: register,
     * enable, boot, then wire it into routes/permissions/contracts/schemas.
     * Extracted from enable()'s transaction body — same 8 steps, same order,
     * just named so the transaction closure reads as one action instead of
     * an inline list.
     */
    private function activatePlugin(Plugin $plugin, Manifest $manifest): void
    {
        $plugin->register();
        $plugin->enable();
        $plugin->boot();
        $this->loadRoutes($plugin);
        $this->registerPermissions($manifest);
        $this->dispatchContractsFor($plugin);
        $this->syncPluginSchemas($plugin);
        $this->persistPluginContentTypes($plugin);
    }

    /**
     * Drop Filament's cached component manifest so a plugin's admin resources,
     * pages, and widgets are re-discovered on the next request. Without this, a
     * warm cache (from `filament:cache-components`, common in production) keeps
     * serving the panel surface from before the plugin changed — the new pages
     * and settings simply never appear. Best-effort: a caching failure must never
     * block enabling/disabling a plugin.
     */
    private function invalidateAdminPanelCache(): void
    {
        try {
            $cached = $this->app->bootstrapPath('cache/filament');

            // Only bother when a panel manifest was actually cached.
            $manifests = glob($cached.'/panels/*.php');
            if (is_array($manifests) && $manifests !== []) {
                Artisan::call('filament:clear-cached-components');
            }
        } catch (Throwable) {
            // No Filament cache command available, or nothing to clear — ignore.
        }
    }

    /**
     * @return array<string, Plugin>
     */
    public function getEnabled(): array
    {
        return $this->booted;
    }

    /**
     * @return list<PluginInfo>
     */
    public function discover(): array
    {
        return $this->discovery->discover();
    }

    /**
     * Ensure every discovered plugin has a row in the `plugins` table so it
     * shows up in the admin plugin list. Plugins that ship pre-bundled in
     * vendor/ (e.g. a marketplace/hub build) are discoverable but never went
     * through the install flow that writes a PluginRecord, so without this
     * they are invisible and can never be enabled. New rows are created
     * DISABLED — a bundled plugin still requires an explicit, deliberate
     * enable (it runs with full application access). Existing rows are left
     * untouched: enabled state, timestamps, and version are never overwritten
     * here.
     */
    public function syncDiscovered(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        $existing = PluginRecord::query()->pluck('name')->all();

        foreach ($this->discovery->discover() as $info) {
            if (in_array($info->manifest->name, $existing, true)) {
                continue;
            }

            PluginRecord::create([
                'name' => $info->manifest->name,
                'display_name' => $info->manifest->displayName,
                'version' => $info->manifest->version,
                'enabled' => false,
                'base_path' => $info->basePath,
                'manifest' => $info->manifest->toArray(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws PluginNotFoundException if the entry class cannot be autoloaded
     */
    private function instantiate(array $manifest, string $basePath): Plugin
    {
        $manifestObj = Manifest::fromArray($manifest);
        $class = $manifestObj->entryClass;

        if (! class_exists($class)) {
            throw new \RuntimeException(
                "Plugin entry class [{$class}] does not exist. "
                .'The plugin files may have been deleted without uninstalling via the admin panel.'
            );
        }

        /** @var Plugin */
        return $this->app->make($class, [
            'app' => $this->app,
            'basePath' => $basePath,
            'manifest' => $manifestObj,
        ]);
    }

    /**
     * S1-07: core module route slugs, reserved at manifest-validation time
     * by ManifestValidator — re-checked here too as defense in depth, since
     * a plugin installed by an older SDK version (before that check
     * existed) could still be sitting in the `plugins` table with a
     * reserved slug. Registering its routes under that slug would let it
     * permanently shadow the matching core module's `api/v1/{slug}/*`
     * routes (Laravel's route table is a plain overwritable array keyed by
     * method+URI, and this module boots after every reserved core module —
     * see MagnaServiceProvider::register()).
     *
     * @var list<string>
     */
    private const RESERVED_ROUTE_SLUGS = [
        'admin', 'audit', 'auth', 'blocks', 'content', 'delivery', 'install',
        'management', 'media', 'plugins', 'privacy', 'settings', 'users', 'webhooks',
    ];

    private function loadRoutes(Plugin $plugin): void
    {
        $slug = Arr::last(explode('/', $plugin->getManifest()->name));

        if (in_array($slug, self::RESERVED_ROUTE_SLUGS, true)) {
            Log::warning(
                "Plugin \"{$plugin->getManifest()->name}\" uses a reserved route slug \"{$slug}\" — refusing to register its routes to avoid shadowing a core module.",
            );

            return;
        }

        $apiRoutesFile = $plugin->getBasePath().'/routes/api.php';
        if (file_exists($apiRoutesFile)) {
            Route::middleware('api')
                ->prefix('api/v1/'.$slug)
                ->group($apiRoutesFile);
        }

        $webRoutesFile = $plugin->getBasePath().'/routes/web.php';
        if (file_exists($webRoutesFile)) {
            Route::middleware('web')
                ->group($webRoutesFile);
        }
    }

    private function registerPermissions(Manifest $manifest): void
    {
        if ($manifest->permissions === []) {
            return;
        }

        /** @var PermissionRegistry $registry */
        $registry = $this->app->make(PermissionRegistry::class);
        foreach ($manifest->permissions as $permission) {
            $registry->register($permission);
        }
    }

    private function runMigrations(string $basePath): void
    {
        $migrationsPath = $basePath.'/database/migrations';
        if (! is_dir($migrationsPath)) {
            return;
        }

        Artisan::call('migrate', [
            '--path' => $migrationsPath,
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    private function purge(PluginRecord $record): void
    {
        /** @var array<string, mixed> $manifest */
        $manifest = $record->manifest;
        $uninstall = $manifest['uninstall'] ?? null;
        if (! is_array($uninstall)) {
            return;
        }

        $tables = $uninstall['tables'] ?? [];
        if (is_array($tables)) {
            foreach ($tables as $table) {
                if (is_string($table) && ! $this->isCoreTable($table)) {
                    Schema::dropIfExists($table);
                }
            }
        }
    }

    /**
     * Prevent a tampered plugin manifest from dropping core Magna tables.
     * A compromised manifest could otherwise wipe users, payments, etc.
     */
    private function isCoreTable(string $table): bool
    {
        static $coreTables = [
            'users', 'personal_access_tokens', 'plugins', 'content_types',
            'media', 'media_conversions', 'media_folders', 'revisions',
            'webhook_subscriptions', 'webhook_deliveries', 'admin_action_logs',
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'sessions', 'password_reset_tokens',
        ];

        return in_array($table, $coreTables, true);
    }

    /**
     * Remove the content types a plugin owns from the registry and the
     * content_types table so their navigation and resources disappear.
     * When $dropTables is true, the physical magna_entries_* tables are
     * dropped too (destructive — purge only).
     */
    private function deregisterContentTypes(PluginRecord $record, bool $dropTables): void
    {
        if (! Schema::hasTable('content_types')) {
            return;
        }

        $registry = $this->app->bound(SchemaRegistry::class)
            ? $this->app->make(SchemaRegistry::class)
            : null;

        foreach ($this->ownedContentTypeHandles($record) as $handle) {
            ContentTypeRecord::query()->where('handle', $handle)->delete();
            $registry?->forget($handle);

            if ($dropTables) {
                Schema::dropIfExists('magna_entries_'.$handle);
            }
        }
    }

    /**
     * Re-assert content_types records for a plugin's types on enable. Needed
     * because SchemaSyncer skips the upsert when the physical table already
     * exists (e.g. re-enabling after a non-purge uninstall), which would
     * otherwise leave the record missing.
     */
    private function persistPluginContentTypes(Plugin $plugin): void
    {
        if (! Schema::hasTable('content_types') || ! $this->app->bound(SchemaRegistry::class)) {
            return;
        }

        /** @var SchemaRegistry $registry */
        $registry = $this->app->make(SchemaRegistry::class);

        $handles = $this->ownedContentTypeHandles(
            $plugin->getBasePath(),
            $plugin->getManifest()->toArray(),
        );

        foreach ($handles as $handle) {
            $type = $registry->get($handle);
            if ($type === null) {
                continue;
            }

            ContentTypeRecord::updateOrCreate(
                ['handle' => $type->handle],
                [
                    'display_name' => $type->displayName,
                    'is_database_defined' => false,
                    'schema' => $type->toArray(),
                ],
            );
        }
    }

    /**
     * The content type handles a plugin owns. Authoritative source is the
     * plugin's schemas/ directory; the manifest's provides.contentTypes and
     * uninstall.contentTypes are merged in as a fallback so a plugin can list
     * types it registers programmatically rather than via schema files.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return list<string>
     */
    private function ownedContentTypeHandles(PluginRecord|string $recordOrBasePath, ?array $manifest = null): array
    {
        if ($recordOrBasePath instanceof PluginRecord) {
            $basePath = $recordOrBasePath->base_path;
            $manifest = $recordOrBasePath->manifest;
        } else {
            $basePath = $recordOrBasePath;
        }

        $handles = [];

        foreach (glob($basePath.'/schemas/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['handle']) && is_string($decoded['handle'])) {
                $handles[] = $decoded['handle'];
            }
        }

        $manifest ??= [];
        $provides = $manifest['provides'] ?? [];
        if (is_array($provides) && is_array($provides['contentTypes'] ?? null)) {
            foreach ($provides['contentTypes'] as $handle) {
                if (is_string($handle)) {
                    $handles[] = $handle;
                }
            }
        }

        $uninstall = $manifest['uninstall'] ?? [];
        if (is_array($uninstall) && is_array($uninstall['contentTypes'] ?? null)) {
            foreach ($uninstall['contentTypes'] as $handle) {
                if (is_string($handle)) {
                    $handles[] = $handle;
                }
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * Create (or update) the magna_entries_* table for every content type that
     * the plugin declares in its schemas/ directory. Called after enable() loads
     * those schemas into the SchemaRegistry so SchemaSyncer sees them.
     */
    private function syncPluginSchemas(Plugin $plugin): void
    {
        if (! is_dir($plugin->getBasePath().'/schemas')) {
            return;
        }

        if (! $this->app->bound(SchemaSyncer::class)) {
            return;
        }

        /** @var SchemaRegistry $registry */
        $registry = $this->app->make(SchemaRegistry::class);
        /** @var SchemaSyncer $syncer */
        $syncer = $this->app->make(SchemaSyncer::class);
        $syncer->syncAll($registry, allowDestructive: false);
    }

    private function dispatchContracts(): void
    {
        foreach ($this->booted as $name => $plugin) {
            try {
                $this->dispatchContractsFor($plugin);
            } catch (Throwable $e) {
                // A plugin whose nav/schema registration threw must not block the panel.
                unset($this->booted[$name]);
                logger()->error("Plugin [{$name}] removed after contract dispatch failed: {$e->getMessage()}");
            }
        }
    }

    private function dispatchContractsFor(Plugin $plugin): void
    {
        if ($plugin instanceof RegistersAdminNavigation) {
            $this->app->instance(
                'magna.nav.'.$plugin->getManifest()->name,
                $plugin->adminNavigation(),
            );
        }

        // Load plugin content type schemas from schemas/ directory.
        $schemasDir = $plugin->getBasePath().'/schemas';
        if (is_dir($schemasDir)) {
            /** @var SchemaRegistry $schemaRegistry */
            $schemaRegistry = $this->app->make(SchemaRegistry::class);
            $schemaRegistry->loadFromDirectory($schemasDir);
        }

        // Wire RegistersBlocks: load plugin block definitions into the BlockRegistry.
        if ($plugin instanceof RegistersBlocks) {
            /** @var BlockRegistry $blockRegistry */
            $blockRegistry = $this->app->make(BlockRegistry::class);
            foreach ($plugin->blocks() as $definition) {
                $blockRegistry->register($definition);
            }
        }

        // Wire ExtendsEntryForm: accumulate plugins in the container so EntryResource can merge their components.
        if ($plugin instanceof ExtendsEntryForm) {
            /** @var list<ExtendsEntryForm> $current */
            $current = $this->app->bound('magna.entry_form_plugins')
                ? $this->app->make('magna.entry_form_plugins')
                : [];
            $current[] = $plugin;
            $this->app->instance('magna.entry_form_plugins', $current);
        }

        // Wire DecoratesDeliveryResponse: accumulate plugins in the container so
        // EntryTransformer can inject their data into delivery API responses.
        if ($plugin instanceof DecoratesDeliveryResponse) {
            /** @var list<DecoratesDeliveryResponse> $current */
            $current = $this->app->bound('magna.delivery_decorators')
                ? $this->app->make('magna.delivery_decorators')
                : [];
            $current[] = $plugin;
            $this->app->instance('magna.delivery_decorators', $current);
        }

        // TODO Stage 10: RegistersDashboardWidgets
        // TODO Stage 10: RegistersSettingsPages
        // FiltersApiQuery: deferred to Phase 3 — none of the Stage 14 first-party
        // plugins require query scoping, so wiring it now would be unused infrastructure.
        // TODO Stage 9:  RegistersWebhookEvents
    }
}
