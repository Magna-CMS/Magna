<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Magna\Contracts\RegistersCommands;
use Throwable;

/**
 * Makes a just-enabled plugin's Artisan commands callable in the CURRENT
 * process.
 *
 * PluginsServiceProvider registers plugin commands while the console kernel
 * is being built, which covers every normal CLI run. What it cannot cover is
 * a plugin enabled after that point: Artisan's starting callbacks have
 * already fired, so the command exists on disk, is listed by the plugin, and
 * still answers "command not found". A scheduler or installer that enables a
 * plugin and then tries to run one of its commands hits exactly that.
 *
 * Extracted from PluginManager for the same reason PluginSecurityGuard and
 * PluginRouteRegistrar were: lifecycle orchestration and per-subsystem
 * registration are separate jobs.
 */
class PluginCommandRegistrar
{
    public function __construct(private readonly Application $app) {}

    public function register(Plugin $plugin): void
    {
        if (! $plugin instanceof RegistersCommands || ! $this->app->runningInConsole()) {
            return;
        }

        $commands = $plugin->commands();

        if ($commands === []) {
            return;
        }

        try {
            foreach ($commands as $command) {
                Artisan::registerCommand($this->app->make($command));
            }
        } catch (Throwable $e) {
            // A plugin whose commands cannot be registered is still enabled;
            // this costs the CLI surface only, and the next process picks
            // them up through the service provider anyway.
            logger()->warning(
                'Could not register commands for plugin ['.$plugin->getManifest()->name."]: {$e->getMessage()}"
            );
        }
    }
}
