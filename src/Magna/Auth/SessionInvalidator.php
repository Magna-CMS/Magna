<?php

declare(strict_types=1);

namespace Magna\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drops every stored session belonging to a user.
 *
 * Rotating `remember_token` kills remember-me cookies and deleting Sanctum
 * tokens kills API access, but neither touches a session that is already
 * established — so before this existed, an attacker holding a live admin
 * session cookie kept it straight through the victim's password reset.
 *
 * Only the database session driver stores sessions in a form that can be
 * queried by user. For the others, `Illuminate\Session\Middleware\
 * AuthenticateSession` (registered on the web group in bootstrap/app.php) is
 * the driver-agnostic backstop: it records the password hash in the session
 * and invalidates any session whose recorded hash no longer matches. This
 * class is the immediate, same-instant half of the pair.
 */
final class SessionInvalidator
{
    public static function forUser(Authenticatable $user): void
    {
        if (Config::string('session.driver', 'file') !== 'database') {
            // Not an error: AuthenticateSession covers this case on the very
            // next request the stale session makes.
            return;
        }

        try {
            DB::connection(Config::string('session.connection', '') ?: null)
                ->table(Config::string('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->delete();
        } catch (Throwable $e) {
            // Never let session cleanup fail the password reset itself — a
            // user locked out of resetting their own password is a worse
            // outcome than a session that AuthenticateSession will kill on its
            // next request anyway.
            Log::warning('Could not purge stored sessions after a credential change.', [
                'user_id' => $user->getAuthIdentifier(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
