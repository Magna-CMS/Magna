<?php

declare(strict_types=1);

namespace Magna\Admin\Support;

class ActionLabel
{
    /** @var array<string, string> */
    private static array $map = [
        'auth.login.success' => 'Login',
        'auth.login.failed' => 'Login Failed',
        'auth.logout' => 'Logout',
        'auth.password.changed' => 'Password Changed',
        'auth.password.reset' => 'Password Reset',
        'auth.2fa.enabled' => '2FA Enabled',
        'auth.2fa.disabled' => '2FA Disabled',
        'media.uploaded' => 'File Uploaded',
        'media.updated' => 'File Updated',
        'media.deleted' => 'File Deleted',
        'media.restored' => 'File Restored',
        'media.folder.created' => 'Folder Created',
        'media.folder.deleted' => 'Folder Deleted',
        'roles.assigned' => 'Role Assigned',
        'roles.removed' => 'Role Removed',
        'settings.updated' => 'Settings Updated',
        'content.created' => 'Content Created',
        'content.updated' => 'Content Updated',
        'content.deleted' => 'Content Deleted',
        'content.published' => 'Content Published',
        'content.unpublished' => 'Content Unpublished',
        'content.restored' => 'Content Restored',
        'user.created' => 'User Created',
        'user.updated' => 'User Updated',
        'user.deleted' => 'User Deleted',
        'user.suspended' => 'User Suspended',
        'user.activated' => 'User Activated',
        'user.invited' => 'User Invited',
        'plugin.installed' => 'Plugin Installed',
        'plugin.enabled' => 'Plugin Enabled',
        'plugin.disabled' => 'Plugin Disabled',
        'plugin.uninstalled' => 'Plugin Uninstalled',
        'api_keys.created' => 'API Key Created',
        'api_keys.revoked' => 'API Key Revoked',
        'tokens.created' => 'API Token Created',
        'tokens.revoked' => 'API Token Revoked',
        'backup.completed' => 'Backup Completed',
        'backup.failed' => 'Backup Failed',
    ];

    public static function get(string $action): string
    {
        return self::$map[$action]
            ?? ucwords(str_replace(['.', '_', '-'], ' ', $action));
    }
}
