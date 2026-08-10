<?php

declare(strict_types=1);

namespace Magna\Notifications;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends a notification without needing a queue worker.
 *
 * Laravel 11+ defaults `QUEUE_CONNECTION` to `database`, and a notification that
 * implements ShouldQueue is therefore written to the `jobs` table and waits for a
 * worker. A Magna install put up through the web installer has no worker and no
 * cron to start one, so on those sites a queued notification is simply never
 * delivered: the row sits in `jobs`, nothing errors, and the person who was
 * mentioned hears nothing.
 *
 * This is the same shape of failure the Roya licence heartbeat had, and it has
 * the same answer: do the work in a terminating callback. The response has
 * already been sent by then, so a slow SMTP server costs the person browsing
 * nothing, and delivery does not depend on infrastructure the installer cannot
 * put in place.
 *
 * A site that *does* run a worker loses nothing worth having — retries and
 * backoff — and gains delivery that cannot silently stop when the worker dies.
 * If that trade is ever worth revisiting, this is the one place to change.
 */
final class DeferredNotifier
{
    public function __construct(private readonly Application $app) {}

    /**
     * A Collection is an object, so the union is array|object and nothing more —
     * naming Collection alongside it is a fatal redundancy, not documentation.
     *
     * @param  Collection<int, mixed>|array<int, mixed>|object  $recipients
     */
    public function send(array|object $recipients, BaseNotification $notification): void
    {
        $this->app->terminating(function () use ($recipients, $notification): void {
            try {
                // sendNow, not send: ShouldQueue on the notification would
                // otherwise put it straight back on the queue nobody is working.
                Notification::sendNow($recipients, $notification);
            } catch (Throwable $exception) {
                // The response is long gone, so throwing here would only produce
                // an unexplained 500 in the log for a request that succeeded.
                Log::warning('A notification could not be delivered.', [
                    'notification' => $notification::class,
                    'exception' => $exception->getMessage(),
                ]);
            }
        });
    }

    /**
     * Delivers immediately, for callers with no request to terminate.
     *
     * A console command or a scheduled scan has nothing to defer to — the process
     * ends when it ends — so waiting for a terminating callback would mean the
     * mail was never attempted at all.
     *
     * @param  Collection<int, mixed>|array<int, mixed>|object  $recipients
     */
    public function sendNow(array|object $recipients, BaseNotification $notification): void
    {
        try {
            Notification::sendNow($recipients, $notification);
        } catch (Throwable $exception) {
            Log::warning('A notification could not be delivered.', [
                'notification' => $notification::class,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
