<?php

declare(strict_types=1);

namespace Magna\Audit\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Magna\Audit\AuditLog;

class RecordLoginSuccess
{
    public function handle(Login $event): void
    {
        // S1-09: this listener previously trusted $event->user unconditionally.
        // Since Login is a public event with a public constructor, any code
        // with container access — including an installed plugin's boot()
        // method — can dispatch a fabricated `new Login($guard, $anyUser,
        // false)` to plant a false-attribution audit entry for someone who
        // never logged in. Cross-check the event's user against what the
        // named guard actually has authenticated before trusting it as the
        // actor; a mismatch means the event didn't originate from a real
        // authentication attempt on that guard.
        $actuallyAuthenticatedId = Auth::guard($event->guard)->id();
        $eventUserId = $event->user->getAuthIdentifier();

        $normalize = fn (mixed $id): ?string => match (true) {
            is_string($id) => $id,
            is_int($id) => (string) $id,
            default => null,
        };

        if ($actuallyAuthenticatedId === null || $normalize($actuallyAuthenticatedId) !== $normalize($eventUserId)) {
            Log::warning('Discarded a Login event whose user did not match the authenticated guard state — possible spoofed audit entry.', [
                'guard' => $event->guard,
                'event_user_id' => $eventUserId,
            ]);

            return;
        }

        // 'user' means a Magna user the Audit Logs viewer can resolve by id.
        // A login on any other session guard (a plugin's portal, say) is a
        // different population with its own id space — label it by guard so
        // the entry never reads as a Magna user it is not.
        AuditLog::record(
            action: 'auth.login.success',
            actorId: $normalize($eventUserId),
            actorType: $event->guard === config('auth.defaults.guard') ? 'user' : 'guard:'.$event->guard,
            ip: request()->ip(),
        );
    }
}
