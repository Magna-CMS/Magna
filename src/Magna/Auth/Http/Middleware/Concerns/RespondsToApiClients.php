<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Middleware\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response shapes and rate-limit gate the two API auth middlewares
 * share. ApiKeyMiddleware and MagnaApiMiddleware carried byte-identical
 * copies of unauthorized()/forbidden() and the 429 block — the same
 * credentials-boundary logic in two places is exactly where a fix lands
 * once and not twice.
 */
trait RespondsToApiClients
{
    private function unauthorized(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
    }

    /**
     * Enforce the per-credential rate limit. A response means "stop here";
     * null means the attempt was counted and the request may proceed.
     */
    private function rateLimited(string $rateLimitKey, int $limit): ?JsonResponse
    {
        if (RateLimiter::tooManyAttempts($rateLimitKey, $limit)) {
            return response()->json(
                ['message' => 'Too many requests.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => RateLimiter::availableIn($rateLimitKey)],
            );
        }

        RateLimiter::hit($rateLimitKey, 60);

        return null;
    }
}
