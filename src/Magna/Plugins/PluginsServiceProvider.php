<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Support\ServiceProvider;
use Magna\Contracts\RegistersCommands;
use Magna\Frontend\FrontendPageRegistry;
use Magna\Install\Installer;
use Magna\Marketplace\ComposerRunner;
use Magna\Marketplace\ProcessComposerRunner;
use Magna\Plugins\Commands\PluginDisableCommand;
use Magna\Plugins\Commands\PluginDoctorCommand;
use Magna\Plugins\Commands\PluginEnableCommand;
use Magna\Plugins\Commands\PluginInfoCommand;
use Magna\Plugins\Commands\PluginInstallCommand;
use Magna\Plugins\Commands\PluginListCommand;
use Magna\Plugins\Commands\PluginMakeCommand;
use Magna\Plugins\Commands\PluginUninstallCommand;
use Magna\Plugins\Commands\PluginValidateCommand;
use Magna\Updater\CoreUpdater;

class PluginsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Public pages plugins contribute (ProvidesFrontendPages) — the
        // Pages plugin's router and menu picker read this.
        $this->app->singleton(FrontendPageRegistry::class);

        $this->app->singleton(PluginDiscovery::class, function (): PluginDiscovery {
            return new PluginDiscovery($this->app->basePath());
        });

        // Singleton so its "already registered" set is shared — boot registers
        // every enabled plugin, and an install/update in the same request
        // registers again for the plugin it just wrote.
        $this->app->singleton(PluginAutoloader::class);

        $this->app->singleton(PluginManager::class, function (): PluginManager {
            return new PluginManager(
                $this->app,
                $this->app->make(PluginDiscovery::class),
                new PluginSecurityGuard($this->app),
                new PluginContentTypeSyncer($this->app),
                new PluginRouteRegistrar($this->app),
                new DependencyResolver,
                new PluginCommandRegistrar($this->app),
                $this->app->make(PluginAutoloader::class),
                new PluginFileRemover($this->app->basePath()),
                new PluginMigrator,
                new PluginRegistry($this->app->make(PluginDiscovery::class)),
                new PluginContractWirer($this->app),
                new PluginSettingsPurger,
            );
        });

        $this->app->bind(ComposerRunner::class, function (): ProcessComposerRunner {
            return new ProcessComposerRunner(
                $this->app->basePath(),
                $this->app->storagePath('app/composer-home'),
            );
        });
    }

    public function boot(): void
    {
        /** @var PluginManager $manager */
        $manager = $this->app->make(PluginManager::class);

        // Before any plugin boots, because a plugin's entry class names these
        // contracts in its `implements` clause — PHP resolves those the moment
        // the class loads, so a missing one is a fatal, not a missing feature.
        //
        // Needed because a core update ships a newer SDK into vendor/ but
        // cannot regenerate vendor/composer's maps: on a host with no Composer
        // binary there is nothing to run. A contract in a namespace those maps
        // predate would therefore be on disk and still unloadable. Registering
        // the SDK's own PSR-4 rules here closes that, exactly as it already
        // does for a plugin dropped in as a zip.
        $this->registerSdkNamespaces();

        // Enabled plugins live in the database; skip until installed so a fresh
        // unzip (possibly with no database driver yet) renders the installer
        // rather than 500ing while querying the plugins table.
        if (Installer::isInstalled()) {
            $manager->bootEnabledPlugins();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                PluginMakeCommand::class,
                PluginInstallCommand::class,
                PluginEnableCommand::class,
                PluginDisableCommand::class,
                PluginUninstallCommand::class,
                PluginListCommand::class,
                PluginValidateCommand::class,
                PluginInfoCommand::class,
                PluginDoctorCommand::class,
            ]);

            $this->registerPluginCommands($manager);
        }
    }

    /**
     * Teach the running autoloader where the SDK's contracts live.
     *
     * Composer already knows on any install where `composer install` has run
     * since the SDK last changed. This is for the install that was updated
     * from an archive instead: CoreUpdater lays the new SDK down inside
     * vendor/ but cannot regenerate vendor/composer's maps, because a host
     * with no Composer binary has nothing to regenerate them with.
     */
    private function registerSdkNamespaces(): void
    {
        $sdk = $this->app->basePath(CoreUpdater::SDK_PATH);

        // Absent when the SDK is required from somewhere other than vendor/ —
        // a path repository during development, for instance, where Composer's
        // own maps are regenerated on every install and this is unnecessary.
        if (! is_dir($sdk)) {
            return;
        }

        $this->app->make(PluginAutoloader::class)->register($sdk);
    }

    /**
     * Register Artisan commands contributed by enabled plugins that implement
     * RegistersCommands. Done here (in a service provider, while the console
     * kernel is being built) so plugin commands are reliably available on the
     * CLI — a plugin can't hook the console bootstrap itself from its boot().
     */
    private function registerPluginCommands(PluginManager $manager): void
    {
        foreach ($manager->getEnabled() as $plugin) {
            if ($plugin instanceof RegistersCommands) {
                $this->commands($plugin->commands());
            }
        }
    }
}
