<?php

declare(strict_types=1);

namespace Magna\Auth\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Magna\Audit\AuditLog;
use Magna\Auth\Concerns\ResolvesActorId;
use Magna\Auth\MagnaToken;
use Magna\Users\User;

class ApiTokenController extends Controller
{
    use ResolvesActorId;

    /**
     * Create a new API token. Tokens are shown in plaintext once here
     * and stored hashed — the plaintext is never retrievable after this.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', 'in:delivery,management'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $scope = $request->string('scope')->toString();

        $expiryDays = $scope === 'management'
            ? Config::integer('magna.token_expiry.management', 30)
            : Config::integer('magna.token_expiry.delivery', 365);

        $expiresAt = now()->addDays($expiryDays);

        $token = $user->createToken(
            $request->string('name')->toString(),
            [$scope],
            $expiresAt,
        );

        // Persist the extra columns onto the underlying token model.
        $token->accessToken->forceFill([
            'scope' => $scope,
            'rate_limit_per_minute' => $request->integer('rate_limit_per_minute') ?: null,
        ])->save();

        AuditLog::record(
            action: 'tokens.created',
            actorId: $this->actorId(),
            actorType: 'user',
            ip: $request->ip(),
            after: ['name' => $request->string('name')->toString(), 'scope' => $scope],
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'id' => $token->accessToken->id,
            'name' => $token->accessToken->name,
            'scope' => $scope,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 201);
    }

    /** List all tokens for the authenticated user (hashed — plaintext not available). */
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        /** @var Collection<int, MagnaToken> $tokenModels */
        $tokenModels = $user->tokens()->orderByDesc('created_at')->get();

        $tokens = $tokenModels->map(fn (MagnaToken $t): array => [
            'id' => $t->id,
            'name' => $t->name,
            'scope' => $t->scope,
            'expires_at' => $t->expires_at?->toIso8601String(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $tokens]);
    }

    /** Revoke (delete) a specific token. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $deleted = $user->tokens()->where('id', $id)->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Token not found.'], 404);
        }

        AuditLog::record(
            action: 'tokens.revoked',
            actorId: $this->actorId(),
            actorType: 'user',
            ip: $request->ip(),
            before: ['token_id' => $id],
        );

        return response()->json(['message' => 'Token revoked.']);
    }
}
