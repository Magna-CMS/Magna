<?php

declare(strict_types=1);

namespace Magna\Settings;

use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class);
    }

    /**
     * Mail is configured from the panel, so the panel's values have to reach the
     * framework before anything sends. Nothing did this until now: the Email
     * settings page saved host, port, credentials and the from address, and mail
     * went out through whatever `.env` happened to say.
     *
     * In boot() rather than register() because it reads the database, and behind
     * a table check so a fresh install, `migrate:fresh` and the installer itself
     * all keep working.
     */
    public function boot(): void
    {
        $this->app->make(MailConfigurator::class)->apply();
    }
}
