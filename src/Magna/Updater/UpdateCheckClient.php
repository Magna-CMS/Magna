<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Support\Facades\Http;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\Marketplace;
use Magna\Notices\DashboardNotice;
use Magna\Plugins\PluginRecord;
use Magna\Support\InstallFingerprint;

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
            $check = UpdateCheck::query()->updateOrCreate(
                ['type' => 'core', 'slug' => null],
                [
                    'current_version' => MagnaServiceProvider::VERSION,
                    'latest_version' => $result->core->latestVersion,
                    'changelog_url' => $result->core->changelogUrl,
                    'download_url' => $result->core->downloadUrl,
                    'download_sha256' => $result->core->downloadSha256,
                    'download_sha256_signature' => $result->core->downloadSha256Signature,
                    'update_available' => $result->core->updateAvailable,
                    'checked_at' => $now,
                ]
            );

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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>|null
     */
    private function post(string $path, array $payload): ?array
    {
        try {
            $response = Http::timeout(Marketplace::REQUEST_TIMEOUT)
                ->acceptJson()
                ->asJson()
                ->post(Marketplace::API_BASE.$path, $payload);

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
