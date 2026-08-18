<?php

declare(strict_types=1);

namespace Magna\Webhooks;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Magna\Auth\PermissionRegistry;
use Magna\Contracts\RegistersWebhookEvents;
use Magna\Plugins\PluginManager;
use Magna\Webhooks\Console\WebhookDeliveriesPruneCommand;

class WebhookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WebhookDispatcher::class);
        $this->app->singleton(WebhookEventSubscriber::class);
    }

    public function boot(): void
    {
        $this->registerPermissions();
        $this->subscribeToEvents();

        if ($this->app->runningInConsole()) {
            $this->commands([WebhookDeliveriesPruneCommand::class]);
        }

        // Stage 13 (S5-02): webhook_deliveries gets one row per active
        // subscription on every content/media mutation, with no other
        // pruning mechanism — was growing unbounded.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('magna:webhooks:prune-deliveries')->daily();
        });
    }

    private function registerPermissions(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->registerMany([
            'webhooks.manage' => 'Create, update, and delete webhook subscriptions; view delivery logs',
        ]);
    }

    private function subscribeToEvents(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);
        $events->subscribe(WebhookEventSubscriber::class);

        // Allow enabled plugins implementing RegistersWebhookEvents to declare
        // additional event keys. We don't need to do anything with those keys at
        // boot time — they are stored as strings in webhook_subscriptions.events
        // and matched at dispatch time in WebhookEventSubscriber::dispatch().
        if ($this->app->bound(PluginManager::class)) {
            /** @var PluginManager $manager */
            $manager = $this->app->make(PluginManager::class);

            foreach ($manager->getEnabled() as $plugin) {
                if ($plugin instanceof RegistersWebhookEvents) {
                    // Keys returned here are intentionally unused at boot time:
                    // they are only needed for documentation / API validation,
                    // which reads WebhookController::CORE_EVENTS + plugin events.
                    // Keep the call for future registry integration.
                    $plugin->webhookEvents();
                }
            }
        }
    }
}
