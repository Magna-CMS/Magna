<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Magna\Auth\Role;

/**
 * Default roles. Idempotent — safe to re-run on existing installations;
 * grants are only added, never removed, so admin customisations survive.
 *
 * All three operator roles carry `panel.access`, which is what admits them to
 * the admin panel at all. Roles seeded by plugins for their own users must not
 * have it: holding a role is not a reason to be in the CMS back end.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::query()->updateOrCreate(['handle' => 'super-admin'], [
            'name' => 'Super Admin',
            'description' => 'Bypasses all permission checks. Assign sparingly.',
            'is_super_admin' => true,
        ]);

        $admin = Role::query()->updateOrCreate(['handle' => 'admin'], [
            'name' => 'Administrator',
            'description' => 'Full administrative access via explicit grants.',
        ]);
        // blocks.raw_html deliberately NOT granted to 'editor' below — see
        // PageTreeAuthorizer::RAW_HTML_BLOCK_HANDLES: it's a distinct,
        // escalated-trust permission from ordinary content editing, since
        // the html block renders content unescaped and unsanitized (the
        // text block is sanitized and needs no gate).
        $admin->grant('panel.access', 'users.*', 'roles.*', 'settings.*', 'plugins.*', 'audit.*', 'blocks.preview', 'blocks.raw_html');

        $editor = Role::query()->updateOrCreate(['handle' => 'editor'], [
            'name' => 'Editor',
            'description' => 'Creates, edits, and publishes content and media.',
        ]);
        $editor->grant('panel.access', 'content.*', 'media.*', 'blocks.preview');

        $viewer = Role::query()->updateOrCreate(['handle' => 'viewer'], [
            'name' => 'Viewer',
            'description' => 'Read-only access to content.',
        ]);
        $viewer->grant('panel.access', 'content.*.view');
    }
}
