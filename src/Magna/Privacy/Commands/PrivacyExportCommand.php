<?php

declare(strict_types=1);

namespace Magna\Privacy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Magna\Contracts\HandlesPersonalData as PluginHandlesPersonalData;
use Magna\Plugins\PluginManager;
use Magna\Privacy\Contracts\HandlesPersonalData as LegacyHandlesPersonalData;
use Magna\Users\User;

/**
 * Export a GDPR-compliant personal-data archive for a user.
 *
 * Collects core data (user record, API tokens, authored entries) and
 * aggregates data from every enabled plugin that implements HandlesPersonalData.
 * Output is a JSON file written to storage/app/privacy/.
 *
 * Usage:
 *   php artisan magna:privacy:export user@example.com
 *   php artisan magna:privacy:export --id=01HVWXYZ
 */
class PrivacyExportCommand extends Command
{
    use ResolvesPrivacyUser;

    protected $signature = 'magna:privacy:export
        {identifier? : User email address or ULID}
        {--id= : User ULID (alternative to positional argument)}';

    protected $description = 'Export a GDPR personal-data archive for a user';

    public function handle(PluginManager $plugins): int
    {
        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $this->info("Exporting data for: {$user->name} <{$user->email}>");

        $export = [
            'exported_at' => now()->toIso8601String(),
            'user_id' => $user->getKey(),
            'core' => $this->exportCoreData($user),
            'plugins' => [],
        ];

        foreach ($plugins->getEnabled() as $name => $plugin) {
            // Either contract — see PrivacyEraseCommand::handlesPersonalData().
            if ($plugin instanceof PluginHandlesPersonalData || $plugin instanceof LegacyHandlesPersonalData) {
                $export['plugins'][$name] = $plugin->exportPersonalData($user);
                $this->line("  + plugin:{$name}");
            }
        }

        $filename = 'privacy/export-'.$user->id.'-'.Str::ulid().'.json';
        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->error('Failed to encode export data as JSON.');

            return self::FAILURE;
        }

        Storage::disk('local')->put($filename, $json);
        $path = Storage::disk('local')->path($filename);
        $this->info("Archive written to: {$path}");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function exportCoreData(User $user): array
    {
        // Personal access tokens (Sanctum) — exclude the token hash itself.
        $tokens = $user->tokens()
            ->get(['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at'])
            ->toArray();

        return [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status->value,
                'created_at' => $user->created_at?->toIso8601String(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            ],
            'api_tokens' => $tokens,
        ];
    }
}
