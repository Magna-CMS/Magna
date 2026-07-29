<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Settings\ApiSettings;
use Magna\Users\Http\Resources\UserResource;
use Magna\Users\User;

class UserController extends ManagementController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('users.view');

        $apiSettings = ApiSettings::get();
        $perPage = min(max($request->integer('per_page', $apiSettings->default_per_page), 1), $apiSettings->max_per_page);
        // Eager-load roles: UserResource reads the loaded relation, so without
        // this each row would fire its own roles query (N+1).
        $paginator = User::query()->with('roles')->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => UserResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $user): JsonResponse
    {
        Gate::authorize('users.view');

        $record = $this->findOrFail(User::query(), $user, 'User');

        return response()->json(['data' => UserResource::make($record)]);
    }

    public function update(Request $request, string $user): JsonResponse
    {
        Gate::authorize('users.manage');

        $record = $this->findOrFail(User::query(), $user, 'User');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,suspended'],
        ]);

        $record->fill($validated);

        // Stage 3 (C3-08): an admin-changed email previously inherited the
        // verified state of the *previous* address — the new address is
        // unproven until the user (or admin) actually verifies it.
        // email_verified_at isn't in $fillable (not meant to be settable
        // from raw request input), so this is forced explicitly, only
        // ever to null, only when the email actually changed.
        if ($record->isDirty('email')) {
            $record->forceFill(['email_verified_at' => null]);
        }

        $record->save();

        return response()->json(['data' => UserResource::make($record)]);
    }
}
