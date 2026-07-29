<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Audit\AuditLog;
use Magna\Auth\Role;
use Magna\Users\User;

class UserRoleController extends ManagementController
{
    public function store(Request $request, string $user): JsonResponse
    {
        Gate::authorize('roles.manage');

        $record = $this->findOrFail(User::query(), $user, 'User');

        $validated = $request->validate([
            'role' => ['required', 'string'],
        ]);

        $roleName = $validated['role'];
        $role = Role::query()->where('name', $roleName)->first();
        if (! $role instanceof Role) {
            return response()->json(['message' => "Role '{$roleName}' not found."], 404);
        }

        // S1-02: 'roles.manage' alone must never be enough to grant super-admin —
        // that requires the acting user to already be a super admin.
        $actor = auth()->user();
        $actorIsSuperAdmin = $actor instanceof User && $actor->isSuperAdmin();
        if ($role->is_super_admin && ! $actorIsSuperAdmin) {
            return response()->json(['message' => 'Only a super admin can assign a super-admin role.'], 403);
        }

        $record->assignRole($role);

        AuditLog::record(
            action: 'roles.assigned',
            actorId: $this->actorId(),
            ip: $request->ip(),
            subject: $record,
            after: ['role' => $roleName],
        );

        return response()->json(['message' => "Role '{$roleName}' assigned."]);
    }
}
