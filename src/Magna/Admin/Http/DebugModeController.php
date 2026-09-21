<?php

declare(strict_types=1);

namespace Magna\Admin\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Audit\AuditLog;
use Magna\Support\DebugWindow;
use Magna\Users\User;

/**
 * Opens and closes the panel's bounded debug window.
 *
 * Not a method on SystemInfoPage: a Livewire method is callable by anyone who
 * can render the component, and this is the one control on that page that can
 * expose the whole site. A route carries its own middleware and its own gate,
 * and the page keeps rendering for people who may look but not touch.
 */
final class DebugModeController
{
    public function __invoke(Request $request, DebugWindow $window): RedirectResponse
    {
        Gate::authorize('settings.manage');

        // Beyond the permission: turning on stack traces, SQL and environment
        // dumps for every visitor is not something an over-permissioned staff
        // account should reach, so it is held to the accounts that can already
        // grant themselves anything.
        $actor = User::current();

        abort_unless($actor?->isSuperAdmin() ?? false, 403);

        $wasOn = (bool) config('app.debug');
        $actorId = $actor->getKey();

        if ($wasOn) {
            $window->close();
        } else {
            $window->open();
        }

        AuditLog::record(
            action: $wasOn ? 'system.debug_mode.closed' : 'system.debug_mode.opened',
            actorId: is_scalar($actorId) ? (string) $actorId : null,
            actorType: $actor::class,
            ip: $request->ip(),
            before: ['debug' => $wasOn],
            after: ['debug' => ! $wasOn],
        );

        return back();
    }
}
