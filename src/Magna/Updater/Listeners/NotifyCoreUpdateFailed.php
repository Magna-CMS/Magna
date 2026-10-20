<?php

declare(strict_types=1);

namespace Magna\Updater\Listeners;

use Magna\Admin\Notifications\NotificationRecipients;
use Magna\Support\Version;
use Magna\Updater\Events\CoreUpdateFailed;
use Throwable;

/**
 * Tells the admins when a core update did not finish.
 *
 * CoreUpdateFailed was dispatched by the rollback and the finalizer and heard
 * by nobody: an update that rolled itself back overnight, or stopped half-way
 * needing attention, left no trace anywhere an admin looks unless they opened
 * System Info. The bell is where every other background outcome lands.
 */
final class NotifyCoreUpdateFailed
{
    public function handle(CoreUpdateFailed $event): void
    {
        $to = Version::label($event->to);

        [$title, $body, $status] = $event->rolledBack
            ? [
                "Update to {$to} was rolled back",
                'The site is back on '.Version::label($event->from).'. '.$event->reason,
                'warning',
            ]
            : [
                "Update to {$to} needs attention",
                $event->reason.' Open System Info to finish or roll back.',
                'danger',
            ];

        try {
            NotificationRecipients::notifyDashboard($title, $body, $status);
        } catch (Throwable) {
            // The journal and the update log are the record; the bell is best-effort.
        }
    }
}
