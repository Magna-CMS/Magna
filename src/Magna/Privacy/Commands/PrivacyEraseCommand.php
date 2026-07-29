<?php

declare(strict_types=1);

namespace Magna\Privacy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Magna\Contracts\HandlesPersonalData as PluginHandlesPersonalData;
use Magna\Plugins\PluginManager;
use Magna\Privacy\Contracts\HandlesPersonalData as LegacyHandlesPersonalData;
use Magna\Users\User;
use Magna\Users\UserStatus;

/**
 * Erase personal data for a user (GDPR right-to-erasure / right-to-be-forgotten).
 *
 * Checks that every enabled plugin either implements HandlesPersonalData or holds
 * no personal data. If any plugin lacks the contract, the erasure is blocked and
 * reported as incomplete — unless --force is passed.
 *
 * The user record itself is anonymised (not hard-deleted) so that foreign-key
 * references in audit logs and other tables remain intact.
 *
 * Usage:
 *   php artisan magna:privacy:erase user@example.com
 *   php artisan magna:privacy:erase user@example.com --force
 */
class PrivacyEraseCommand extends Command
{
    use ResolvesPrivacyUser;

    protected $signature = 'magna:privacy:erase
        {identifier? : User email address or ULID}
        {--id= : User ULID (alternative to positional argument)}
        {--force : Proceed even if some plugins do not implement HandlesPersonalData}';

    protected $description = 'Erase a user\'s personal data (GDPR erasure request)';

    public function handle(PluginManager $plugins): int
    {
        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $userId = $user->id;
        $this->info("Processing erasure for: {$user->name} <{$user->email}> (ID: {$userId})");

        // Check for non-compliant plugins before doing anything irreversible.
        $incomplete = $this->nonCompliantPlugins($plugins);
        if ($incomplete !== [] && ! $this->option('force')) {
            $this->error('Erasure incomplete — the following enabled plugins do not implement HandlesPersonalData:');
            foreach ($incomplete as $name) {
                $this->line("  - {$name}");
            }
            $this->line('Run with --force to erase core data anyway (plugin data may remain).');

            return self::FAILURE;
        }

        if ($incomplete !== []) {
            $this->warn('Proceeding with --force; the following plugins may retain data:');
            foreach ($incomplete as $name) {
                $this->line("  ! {$name}");
            }
        }

        // Plugin-level erasure first (while the user record still exists).
        foreach ($plugins->getEnabled() as $name => $plugin) {
            if ($plugin instanceof PluginHandlesPersonalData || $plugin instanceof LegacyHandlesPersonalData) {
                $plugin->erasePersonalData($user);
                $this->line("  ✓ plugin:{$name} erased");
            }
        }

        // Revoke all API tokens.
        $user->tokens()->delete();

        // Anonymise the user record — keep the row for referential integrity.
        $userId = $user->id;
        $user->forceFill([
            'name' => 'Deleted User',
            'email' => "deleted_{$userId}@invalid",
            'password' => Str::random(64),
            'status' => UserStatus::Suspended,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'avatar_path' => null,
        ])->saveQuietly();

        $this->info("User {$userId} anonymised. Erasure complete.");

        return self::SUCCESS;
    }

    /**
     * Accepts either the current SDK contract or the deprecated core-side one.
     *
     * Matching only the new interface would silently downgrade every plugin
     * built against the old namespace to "does not handle personal data" —
     * and under --force that means the user is anonymised while the plugin's
     * data survives, reported as a complete erasure.
     */
    private function handlesPersonalData(object $plugin): bool
    {
        return $plugin instanceof PluginHandlesPersonalData
            || $plugin instanceof LegacyHandlesPersonalData;
    }

    /** @return list<string> */
    private function nonCompliantPlugins(PluginManager $plugins): array
    {
        $missing = [];
        foreach ($plugins->getEnabled() as $name => $plugin) {
            if (! $this->handlesPersonalData($plugin)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}
