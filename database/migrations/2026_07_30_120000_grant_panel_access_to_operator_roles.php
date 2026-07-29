<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admits the existing operator roles to the panel, now that the door has a
 * permission of its own.
 *
 * Panel access used to mean "holds any role". That let a plugin's own roles —
 * a client portal's users, a storefront's sellers — into the CMS back end,
 * where they reached whatever page was missing a gate. `panel.access` fixes
 * the rule, and this makes sure nobody who legitimately administers an
 * existing installation is locked out by it.
 *
 * The line drawn here is "already holds a core permission". A role granted
 * `users.*` or `content.*` administers this CMS and keeps its door. A role
 * holding only `erp.*` or `dms.*` belongs to a plugin and does not get one —
 * which is the whole point.
 *
 * Super admins are unaffected either way: they bypass every check.
 */
return new class extends Migration
{
    /**
     * Prefixes that mean "this role operates the CMS itself".
     *
     * Written out rather than read from the permission registry, which is
     * populated at boot by whichever plugins happen to be enabled — a
     * migration that changes its mind depending on that is not a migration.
     *
     * @var list<string>
     */
    private const CORE_PREFIXES = [
        'users.', 'roles.', 'settings.', 'plugins.', 'audit.',
        'content.', 'media.', 'tokens.', 'blocks.', 'licensing.',
    ];

    public function up(): void
    {
        $roles = DB::table('roles')->get();

        foreach ($roles as $role) {
            if ((bool) ($role->is_super_admin ?? false)) {
                continue;
            }

            $grants = DB::table('role_permissions')
                ->where('role_id', $role->id)
                ->pluck('permission');

            if ($grants->contains('panel.access')) {
                continue;
            }

            $operatesTheCms = $grants->contains(
                fn (string $permission): bool => $this->isCorePermission($permission),
            );

            if (! $operatesTheCms) {
                continue;
            }

            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission' => 'panel.access',
            ]);
        }
    }

    private function isCorePermission(string $permission): bool
    {
        foreach (self::CORE_PREFIXES as $prefix) {
            if (str_starts_with($permission, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'panel.access')->delete();
    }
};
