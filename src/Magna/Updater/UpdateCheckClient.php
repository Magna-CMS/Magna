<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\Marketplace;
use Magna\Marketplace\MarketplaceHttp;
use Magna\Notices\DashboardNotice;
use Magna\Plugins\PluginRecord;
use Magna\Support\InstallFingerprint;
use Magna\Updater\Engine\EngineLoader;
use Magna\Updater\Manifest\ManifestRules;

/**
 * Talks to Update Manager's check-in endpoint (Magna\Marketplace\Marketplace::API_BASE.'/updates'),
 * hosted alongside the plugin catalog on managemagna.jrstudios.dev.
 *
 * This is the only place a Magna install ever asks "is there something new?" —
 * it never queries GitHub directly. Update Manager has already curated which
 * releases are published; this call is a cheap lookup against that, not a
 * version comparison the client has to do itself.
 *
 * Best-effort like MarketplaceClient: never throws, degrades to "no data" if
 * the server is unreachable so a broken network never blocks the admin panel.
 */
class UpdateCheckClient
{
    /**
     * Ask Update Manager what's current, given what's installed here, and
     * persist the answer into update_checks so the dashboard notice and the
     * System Info page both read the same, already-fresh state.
     */
    public function checkIn(): ?UpdateCheckResult
    {
        $installedPlugins = PluginRecord::query()
            ->get(['name', 'version'])
            ->mapWithKeys(fn (PluginRecord $r): array => [$r->name => $r->version])
            ->all();

        $payload = [
            'site' => InstallFingerprint::derive(),
            'core' => MagnaServiceProvider::VERSION,
            'plugins' => $installedPlugins,
            // What this host runs and what this updater can consume, so Update
            // Manager can withhold a release the site could not apply — a PHP
            // floor it does not meet, an archive shape this code does not read
            // — instead of announcing it and letting the apply fail here.
            // `updater` is bumped as the client learns: `manifest` is the
            // release-manifest schema it reads (0: none yet), `engine` the
            // engine APIs it can hand off to.
            'php' => PHP_VERSION,
            'extensions' => self::loadedExtensions(),
            'updater' => [
                'manifest' => max(ManifestRules::SUPPORTED_SCHEMAS),
                'engine' => EngineLoader::SUPPORTED_APIS,
                'vendor' => ManifestRules::VENDOR_STRATEGIES,
                'signature' => ['sha256', 'sha256+version'],
            ],
        ];

        $raw = $this->post('/updates', $payload);
        if ($raw === null) {
            return null;
        }

        $result = UpdateCheckResult::fromArray($raw);
        $this->persist($result, $installedPlugins, new UpdateChangeNotifier);
        $this->syncNotices($result->notices, new UpdateChangeNotifier);

        return $result;
    }

    /** @param  array<string, string>  $installedPlugins */
    private function persist(UpdateCheckResult $result, array $installedPlugins, UpdateChangeNotifier $notifier): void
    {
        $now = now();

        if ($result->core !== null) {
            $attributes = [
                'current_version' => MagnaServiceProvider::VERSION,
                'latest_version' => $result->core->latestVersion,
                'changelog_url' => $result->core->changelogUrl,
                'download_url' => $result->core->downloadUrl,
                'download_sha256' => $result->core->downloadSha256,
                'download_sha256_signature' => $result->core->downloadSha256Signature,
                'update_available' => $result->core->updateAvailable,
                'checked_at' => $now,
            ];

            // Only once the columns are there: a check-in can run between a
            // switch and the finalize that migrates, and must not fail the
            // whole persist for a hint.
            if (Schema::hasColumn('update_checks', 'installed_download_sha256_signature')) {
                $attributes += [
                    'requires_php' => $result->core->requiresPhp,
                    'min_upgrade_from' => $result->core->minUpgradeFrom,
                    'installed_download_url' => $result->core->installedDownloadUrl,
                    'installed_download_sha256' => $result->core->installedDownloadSha256,
                    'installed_download_sha256_signature' => $result->core->installedDownloadSha256Signature,
                ];
            }

            $check = UpdateCheck::query()->updateOrCreate(['type' => 'core', 'slug' => null], $attributes);

            if ($check->update_available) {
                $notifier->notifyIfChanged(
                    $check,
                    ['latest_version', 'update_available'],
                    'Core update available',
                    "Magna v{$check->latest_version} is available (currently running v{$check->current_version}).",
                );
            }
        }

        foreach ($result->plugins as $slug => $plugin) {
            $check = UpdateCheck::query()->updateOrCreate(
                ['type' => 'plugin', 'slug' => $slug],
                [
                    'current_version' => $installedPlugins[$slug] ?? 'unknown',
                    'latest_version' => $plugin->latestVersion,
                    'changelog_url' => $plugin->changelogUrl,
                    'update_available' => $plugin->updateAvailable,
                    'license_required' => $plugin->licenseRequired,
                    'checked_at' => $now,
                ]
            );

            if ($check->update_available) {
                $notifier->notifyIfChanged(
                    $check,
                    ['latest_version', 'update_available'],
                    'Plugin update available',
                    "{$slug} v{$check->latest_version} is available (currently running v{$check->current_version}).",
                );
            }
        }
    }

    /**
     * Reconciles the local dashboard_notices cache to exactly the set Update
     * Manager just sent — inserts new ones, updates changed copy, deletes
     * ones no longer active (Update Manager unpublished or un-targeted this
     * site), and never touches dismissed_at on a notice that's still active,
     * so a user's dismissal survives re-syncs of the same notice.
     *
     * @param  list<NoticeEntry>  $notices
     */
    private function syncNotices(array $notices, UpdateChangeNotifier $notifier): void
    {
        $activeRemoteIds = [];

        foreach ($notices as $notice) {
            $activeRemoteIds[] = $notice->id;

            $record = DashboardNotice::query()->updateOrCreate(
                ['remote_id' => $notice->id],
                [
                    'category' => $notice->category,
                    'category_description' => $notice->categoryDescription,
                    'image_url' => $notice->imageUrl,
                    'title' => $notice->title,
                    'description' => $notice->description,
                    'link_github' => $notice->linkGithub,
                    'link_docs' => $notice->linkDocs,
                    'link_blog' => $notice->linkBlog,
                    'link_community' => $notice->linkCommunity,
                    'link_themes' => $notice->linkThemes,
                    'link_plugins' => $notice->linkPlugins,
                ]
            );

            $label = match ($record->category) {
                'system_upgrade' => 'System upgrade notice',
                'welcome' => 'Welcome message',
                default => 'New announcement',
            };

            $notifier->notifyIfChanged($record, ['title', 'description', 'category'], $label, $record->title);
        }

        DashboardNotice::query()->whereNotIn('remote_id', $activeRemoteIds)->delete();
    }

    /** @return list<string> lower-cased, sorted, so the hub can compare without normalising */
    private static function loadedExtensions(): array
    {
        $extensions = array_map(strtolower(...), get_loaded_extensions());
        sort($extensions);

        return $extensions;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>|null
     */
    private function post(string $path, array $payload): ?array
    {
        return MarketplaceHttp::jsonOrNull(fn () => Http::timeout(Marketplace::REQUEST_TIMEOUT)
            ->acceptJson()
            ->asJson()
            ->post(Marketplace::API_BASE.$path, $payload));
    }
}
