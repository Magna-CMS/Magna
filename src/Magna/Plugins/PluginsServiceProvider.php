<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Support\ServiceProvider;
use Magna\Contracts\RegistersCommands;
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

class PluginsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginDiscovery::class, function (): PluginDiscovery {
            return new PluginDiscovery($this->app->basePath());
        });

        $this->app->singleton(PluginManager::class, function (): PluginManager {
            return new PluginManager(
                $this->app,
                $this->app->make(PluginDiscovery::class),
                new PluginSecurityGuard($this->app),
                new PluginContentTypeSyncer($this->app),
                new PluginRouteRegistrar($this->app),
                new DependencyResolver,
                new PluginCommandRegistrar($this->app),
            );
        });

        $this->app->bind(ComposerRunner::class, function (): ProcessComposerRunner {
            return new ProcessComposerRunner($this->app->basePath());
        });
    }

    public function boot(): void
    {
        /** @var PluginManager $manager */
        $manager = $this->app->make(PluginManager::class);

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
