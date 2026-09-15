<?php

declare(strict_types=1);

namespace Magna\Settings;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;

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

        $this->reapplyMailConfigPerOctaneOperation();
    }

    /**
     * Keeps the panel's mail settings applied on a server that boots once.
     *
     * Under Octane a worker survives thousands of requests, and each operation
     * runs against a sandbox holding a fresh clone of the configuration the
     * worker booted with. Folded over that config in boot() alone, the settings
     * reached exactly one process at one moment: an administrator who pasted a
     * corrected SMTP password went on getting
     *
     *     Failed to authenticate on SMTP server with username "resend" […]
     *     535 Missing password
     *
     * until somebody thought to restart the server — three days, in the install
     * that found this. Worse, the one tool built to diagnose it lied: MailTester
     * folds the settings over the config itself before sending, so the test
     * button reported success the whole time real password-reset mail failed.
     *
     * The configurator is resolved from `$event->sandbox` rather than captured
     * here, and that is the whole trick. Octane's own CreateConfigurationSandbox
     * listener rebinds `config` to a clone on the sandbox container before this
     * runs, so a configurator built at boot would hold the repository that clone
     * replaced and write every value into an object nothing reads.
     *
     * Registered as a listener rather than added to config/octane.php so an
     * install with a customised listener list still gets it, and guarded by
     * class_exists so an install without Octane neither pays for it nor breaks.
     * MagnaServiceProvider is an application provider, so it boots after the
     * package providers and this runs after Octane's own sandbox setup.
     */
    private function reapplyMailConfigPerOctaneOperation(): void
    {
        if (! class_exists(RequestReceived::class)) {
            return;
        }

        Event::listen(
            [RequestReceived::class, TaskReceived::class, TickReceived::class],
            static function (RequestReceived|TaskReceived|TickReceived $event): void {
                $event->sandbox->make(MailConfigurator::class)->apply();
            },
        );
    }
}
