<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Magna\Auth\Http\Middleware\Concerns\RespondsToApiClients;
use Magna\Auth\MagnaToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a Magna API bearer token, enforces its scope, checks expiry,
 * and applies per-token rate limiting.
 *
 * Usage as an alias: `magna.api` (registered in AuthServiceProvider).
 * Scope enforcement: `magna.api:management` rejects delivery tokens.
 */
class MagnaApiMiddleware
{
    use RespondsToApiClients;

    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $requiredScope  'delivery', 'management', or '' (any valid token)
     */
    public function handle(Request $request, Closure $next, string $requiredScope = ''): Response
    {
        /** @var MagnaToken|null $token */
        $token = $request->bearerToken()
            ? Sanctum::$personalAccessTokenModel::findToken($request->bearerToken())
            : null;

        if ($token === null) {
            return $this->unauthorized('Missing or invalid API token.');
        }

        if ($token->isExpired()) {
            return $this->unauthorized('API token has expired.');
        }

        if ($requiredScope === 'management' && $token->isDelivery()) {
            return $this->forbidden('A management-scope token is required for this endpoint.');
        }

        if ($requiredScope === 'delivery' && $token->isManagement()) {
            return $this->forbidden('A delivery-scope token is required for this endpoint.');
        }

        // S1-15: defense in depth. The scope checks above are the primary
        // enforcement (a custom `scope` column, always kept in lockstep with
        // Sanctum's own `abilities` array at issuance — see
        // ApiTokenController::store()). This also asserts the token's
        // Sanctum abilities agree, so that if any future code path ever
        // creates a token with a scope/abilities mismatch, it fails closed
        // here rather than silently having its `abilities` ignored.
        if ($requiredScope !== '' && ! $token->can($requiredScope)) {
            return $this->forbidden("This token's abilities do not include \"{$requiredScope}\".");
        }

        $limited = $this->rateLimited('api_token:'.$token->id, $token->effectiveRateLimit());
        if ($limited !== null) {
            return $limited;
        }

        $tokenable = $token->tokenable;

        if (! $tokenable instanceof Authenticatable) {
            return $this->unauthorized('API token refers to a deleted or invalid user.');
        }

        // Stage 10 (A-4) defense in depth: User::booted() already revokes
        // all tokens the instant an account is suspended, but this
        // independent per-request check fails closed even if that model
        // hook is ever bypassed (e.g. a direct DB status update) or a
        // future tokenable type doesn't wire the same hook.
        if (method_exists($tokenable, 'isActive') && ! $tokenable->isActive()) {
            return $this->unauthorized('This account is suspended.');
        }

        $request->setUserResolver(fn () => $tokenable);
        auth()->setUser($tokenable);

        $token->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
