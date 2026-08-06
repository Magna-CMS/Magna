<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Magna\Blocks\BlockRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Contracts\DecoratesDeliveryResponse;
use Magna\Contracts\ExtendsEntryForm;
use Magna\Contracts\RegistersAdminNavigation;
use Magna\Contracts\RegistersBlocks;
use Magna\Licensing\LicenseGate;
use Magna\MagnaServiceProvider;
use Magna\Plugins\Exceptions\DependencyException;
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
        private readonly PluginSecurityGuard $security,
        private readonly PluginContentTypeSyncer $contentTypes,
        private readonly PluginRouteRegistrar $routes,
        private readonly DependencyResolver $dependencies,
        private readonly PluginCommandRegistrar $commandRegistrar,
        private readonly PluginAutoloader $autoloader,
        private readonly PluginFileRemover $fileRemover,
        private readonly PluginMigrator $migrator,
        private readonly PluginRegistry $registry,
    ) {}

    /**
     * Called from PluginsServiceProvider::boot(). Loads all enabled plugins and
     * runs register() then boot() on each, registering their routes and
     * permissions. A plugin's register()/boot() can tamper with
     * security-critical middleware/singletons/listeners; PluginSecurityGuard
     * snapshots those before the boot pass and reverts any tampering after —
     * see that class for the full threat model.
     */
    public function bootEnabledPlugins(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        $integritySnapshot = $this->security->capture();

        /** @var Collection<string, PluginRecord> $records */
        $records = PluginRecord::query()->where('enabled', true)->get()->keyBy('name');

        // A paid plugin whose licence has ended must not run, even if the
        // plugins table still says enabled. LicenseEnforcer normally disables
        // it at the next verify, but this check closes the window in between
        // — and the window that opens if someone flips `enabled` back on by
        // hand. Cache-only and offline-safe by contract (see LicenseGate).
        // A free plugin has no licence entry and is never affected; a plugin
        // that arrived through the licensed download path is, because for it
        // "no entry" means the licence is gone rather than never needed.
        $records = $records->reject(
            fn (PluginRecord $record): bool => app(LicenseGate::class)
                ->isLocked($record->name, (bool) $record->requires_license)
        );

        // Register/boot in dependency order (a plugin's dependencies boot first).
        // enable() already validates the graph, so a failure here means on-disk
        // state drifted (e.g. a dependency was disabled) — fall back to a stable
        // name order rather than bricking the panel, and let the per-plugin
        // guards below auto-disable anything that then fails to boot.
        $order = $this->resolveBootOrder($records);

        // First pass — register() on every plugin (so container bindings are in place for boot()).
        // A plugin whose files were deleted after installation must not crash the entire CMS.
        // Auto-disable any plugin that fails here so the admin panel remains accessible.
        foreach ($order as $name) {
            $record = $records->get($name);
            if ($record === null) {
                continue;
            }
            try {
                // A plugin dropped on disk by the Core Plugin Manager's zip
                // upload is not in vendor/composer/autoload_*, and a host with
                // no Composer binary has nothing to regenerate them with —
                // without this its entry class is unautoloadable and the catch
                // below would auto-disable it as "files missing".
                $this->autoloader->register((string) $record->base_path);

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
                $this->routes->loadRoutes($plugin);
                $this->routes->registerPermissions($plugin->getManifest());
            } catch (Throwable $e) {
                unset($this->booted[$name]);
                logger()->error("Plugin [{$name}] auto-disabled during boot: {$e->getMessage()}");
            }
        }

        // Dispatch typed contracts.
        $this->dispatchContracts();

        $this->security->verify($integritySnapshot);
    }

    /**
     * Validate, install, and enable a plugin.
     *
     * @throws PluginNotFoundException
     * @throws PluginCompatibilityException
     */
    public function enable(string $name): void
    {
        $info = $this->discovery->find($name)
            // A plugin already recorded is installed, whatever discovery can
            // currently see. Licensed installs write into plugins-dev/, which
            // discoverFromDev() skips outright in production and otherwise only
            // reads when the directory is a wired Composer path repository — so
            // enabling one answered "Plugin [x] was not found. Run `composer
            // require x` first." for files sitting on disk. The record carries
            // the manifest and base path this needs; nothing has to be
            // rediscovered to use them.
            ?? $this->registry->find($name);

        if ($info === null) {
            throw new PluginNotFoundException($name);
        }

        if (! $info->manifest->isCompatibleWith(MagnaServiceProvider::VERSION)) {
            throw new PluginCompatibilityException(
                "Plugin [{$name}] requires magna {$info->manifest->magnaCompat} "
                .'but the installed core is '.MagnaServiceProvider::VERSION.'.'
            );
        }

        // Required plugins must be enabled and version-compatible, and nothing
        // enabled may conflict with this one. Throws DependencyException with a
        // developer-facing message the admin UI surfaces.
        $this->dependencies->assertCanEnable($info->manifest, $this->enabledManifests($name));

        // Same reason as bootEnabledPlugins(): the files may have arrived
        // without Composer ever running, and instantiate() below needs the
        // entry class to autoload in *this* request.
        $this->autoloader->register($info->basePath);

        $this->migrator->run($info->basePath);

        // Stage 11 (S11-03): the PluginRecord write, permission
        // registration, and content-type persistence are dependent DML
        // steps — if the last one (persistPluginContentTypes) throws, the
        // earlier ones were previously left committed, so the plugin would
        // show as "enabled" in the admin list while missing the content
        // types it's supposed to provide, with no clean way to recover
        // short of manual DB surgery. The migrator is DDL and stays
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

        $this->commandRegistrar->register($plugin);
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

        // Refuse to disable a plugin another enabled plugin still requires —
        // otherwise the dependent would boot with a missing dependency.
        foreach ($this->enabledManifests($name) as $dependent) {
            if (isset($dependent->requires[$name])) {
                throw new DependencyException(
                    "Cannot disable \"{$name}\": \"{$dependent->name}\" requires it. "
                    ."Disable \"{$dependent->name}\" first."
                );
            }
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
        $this->contentTypes->deregister($record, dropTables: false);

        $record->update(['enabled' => false, 'disabled_at' => now()]);

        // Its admin resources/pages/widgets are gone now — drop any stale panel cache.
        $this->invalidateAdminPanelCache();
    }

    /**
     * Disable and remove a plugin's DB record. With --purge, also drops its declared tables.
     *
     * @throws PluginNotFoundException
     */
    public function uninstall(string $name, bool $purge = false, bool $removeFiles = false): void
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
            $this->contentTypes->deregister($record, dropTables: $purge);

            if ($purge) {
                $this->contentTypes->purge($record);
                // Dropping a plugin's tables while leaving its rows in the
                // migrations ledger makes the purge irreversible in the worst
                // way: enable() re-runs migrate, every migration is already
                // recorded so none of them rebuild the dropped tables, and the
                // plugin can never be installed again on this database. Forget
                // the ledger entries too, so a purge really does return the
                // plugin to "never installed".
                $this->migrator->forget($record->base_path);
            }

            $record->delete();
        });

        // Outside the transaction: filesystem work cannot be rolled back, so
        // it must not run where a later DB failure would imply it had been.
        //
        // Deleting the files is what makes an uninstall stick — without it the
        // record went, the files stayed, and the next syncDiscovered() (which
        // the Plugins page calls on every load) re-created the row from those
        // files. Opt-in rather than automatic: this is a recursive delete
        // against a path derived from a package name, and every caller that
        // only wants the record gone should not have to know that.
        if ($removeFiles) {
            $this->fileRemover->remove($name);
            $this->discovery->reset();
        }

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
        $this->routes->loadRoutes($plugin);
        $this->routes->registerPermissions($manifest);
        $this->dispatchContractsFor($plugin);
        $this->contentTypes->syncSchemas($plugin);
        $this->contentTypes->persist($plugin);
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

        $this->invalidateRouteCache();
    }

    /**
     * Drop the cached route table after a plugin's routes change.
     *
     * Production installs run `route:cache`, and a cached route table is built
     * once from whatever was enabled at deploy time — Laravel skips route
     * registration entirely while it exists, so a plugin enabled afterwards
     * gets no routes at all. Its nav items and dashboard widgets still appear
     * (those are read from the plugins table on every request), and the first
     * one that resolves a page URL throws
     *
     *     Route [filament.magna.pages.…] not defined.
     *
     * on EVERY admin request — locking the admin out of the very page they
     * would disable the plugin from. Clearing costs a little per-request route
     * building until the next deploy re-caches; that beats a dead panel.
     */
    private function invalidateRouteCache(): void
    {
        try {
            $app = $this->app;

            // routesAreCached() is on the concrete application, not the contract.
            if ($app instanceof LaravelApplication && $app->routesAreCached()) {
                Artisan::call('route:clear');
            }
        } catch (Throwable) {
            // Read-only bootstrap/cache, no console kernel — never block the
            // enable/disable that asked for this.
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
     * Delegates to PluginRegistry. Kept on the manager because it is public API
     * — the Core Plugin Manager and the licensed installer both call it, and
     * neither should have to know which collaborator owns the table.
     */
    public function syncDiscovered(): void
    {
        $this->registry->syncDiscovered();
    }

    /**
     * Build the Manifest map for currently-enabled plugins (optionally excluding
     * one, e.g. the plugin being enabled/disabled). Records with an unparseable
     * stored manifest are skipped — they can't participate in the graph.
     *
     * @return array<string, Manifest>
     */
    private function enabledManifests(?string $exclude = null): array
    {
        $map = [];

        foreach (PluginRecord::query()->where('enabled', true)->get() as $record) {
            if ($record->name === $exclude) {
                continue;
            }

            try {
                $map[$record->name] = Manifest::fromArray($record->manifest);
            } catch (Throwable) {
                // Unparseable stored manifest — ignore for graph purposes.
            }
        }

        return $map;
    }

    /**
     * Determine the deterministic boot order for the enabled records. Falls back
     * to a stable name order (and logs) if the on-disk graph can't be resolved,
     * so a drifted dependency never prevents the panel from booting.
     *
     * @param  Collection<string, PluginRecord>  $records
     * @return list<string>
     */
    private function resolveBootOrder(Collection $records): array
    {
        $manifests = [];
        foreach ($records as $name => $record) {
            try {
                $manifests[$name] = Manifest::fromArray($record->manifest);
            } catch (Throwable) {
                // Skip — the register() pass will auto-disable it below.
            }
        }

        try {
            $order = $this->dependencies->resolveBootOrder($manifests);
        } catch (DependencyException $e) {
            logger()->error('Plugin dependency resolution failed; booting in name order. '.$e->getMessage());
            $order = array_keys($manifests);
            sort($order);
        }

        // Append any record whose manifest failed to parse so the register()
        // pass still runs (and auto-disables it).
        foreach ($records as $name => $record) {
            if (! in_array($name, $order, true)) {
                $order[] = $name;
            }
        }

        return $order;
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

        // Wire ExtendsEntryForm: accumulate plugins in the container so the
        // Filament admin EntryResource (Magna\Admin\Resources\EntryResource)
        // can merge their form components.
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

        // The remaining capability contracts are dispatched where their target
        // surface is actually built, not here:
        //   - RegistersDashboardWidgets / RegistersSettingsPages → the Filament
        //     panel in AdminServiceProvider + AdminPanelProvider.
        //   - RegistersWebhookEvents → WebhookServiceProvider (event registry).
        // dispatchContractsFor() only wires the container-backed contracts
        // (navigation, blocks, entry-form extensions, delivery decorators).
    }
}
