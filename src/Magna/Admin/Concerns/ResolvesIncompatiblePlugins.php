<?php

declare(strict_types=1);

namespace Magna\Admin\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Magna\Plugins\PluginManager;
use Magna\Updater\CoreUpdater;
use Magna\Updater\CoreUpdateStarter;
use Magna\Updater\IncompatiblePlugin;
use Magna\Updater\PendingCoreUpdate;
use Magna\Updater\UpdateCheck;
use Throwable;

/**
 * The modal "Update now" opens when enabled plugins declare they do not
 * support the target version, and the two ways out of it: force the update
 * (the incompatible plugins are disabled once the new core is in place) or
 * uninstall them first and update cleanly. Cancelling leaves everything
 * untouched.
 *
 * Extracted from SystemInfoPage so the page stays the size its architecture
 * pin allows. Dependencies arrive through Filament's closure injection.
 */
trait ResolvesIncompatiblePlugins
{
    /**
     * Enabled plugins found incompatible with the pending target version,
     * captured by updateNow's pre-flight check for the resolution modal.
     *
     * @var list<array{name: string, displayName: string, installedVersion: string, requiredCompat: string}>
     */
    #[Locked]
    public array $incompatiblePlugins = [];

    /**
     * Target version captured while the resolveIncompatiblePlugins modal is
     * open.
     *
     * #[Locked] because a Livewire public property round-trips through the
     * browser: without it the client could rewrite the pending target between
     * opening the modal and submitting it. The URL and checksum are no longer
     * held here at all — they are re-read from the update_checks row at
     * dispatch time, so the only thing the browser can influence is *which*
     * already-recorded release is applied, and even that is verified.
     */
    #[Locked]
    public ?string $pendingUpdateVersion = null;

    public function resolveIncompatiblePluginsAction(): Action
    {
        return Action::make('resolveIncompatiblePlugins')
            ->authorize(fn (): bool => auth()->user()?->can('settings.manage') ?? false)
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->modalHeading(fn (): string => count($this->incompatiblePlugins).' plugin(s) are incompatible with v'.($this->pendingUpdateVersion ?? ''))
            ->modalDescription(fn (): HtmlString => $this->incompatiblePluginsModalBody())
            ->modalSubmitActionLabel('Force update anyway')
            ->modalCancelActionLabel('Cancel')
            ->color('danger')
            ->extraModalFooterActions([
                Action::make('uninstallIncompatibleAndContinue')
                    ->label('Uninstall these plugins & continue')
                    ->color('warning')
                    ->authorize(fn (): bool => auth()->user()?->can('settings.manage') ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('Uninstall incompatible plugins?')
                    ->modalDescription('Each plugin listed is uninstalled (data tables are preserved; you can reinstall a compatible version later) and the update then proceeds normally.')
                    ->modalSubmitActionLabel('Uninstall & update')
                    ->action(fn (PluginManager $manager, CoreUpdater $updater, CoreUpdateStarter $starter) => $this->uninstallIncompatibleAndContinue($manager, $updater, $starter)),
            ])
            ->action(fn (CoreUpdateStarter $starter) => $this->forceUpdateAnyway($starter));
    }

    private function incompatiblePluginsModalBody(): HtmlString
    {
        $rows = '';
        foreach ($this->incompatiblePlugins as $plugin) {
            $rows .= '<tr>'
                .'<td class="py-1 pr-4 font-medium">'.e($plugin['displayName']).'</td>'
                .'<td class="py-1 pr-4 text-gray-500 dark:text-gray-400">v'.e($plugin['installedVersion']).'</td>'
                .'<td class="py-1 text-gray-500 dark:text-gray-400">requires magna '.e($plugin['requiredCompat']).'</td>'
                .'</tr>';
        }

        $html = '<div class="space-y-3">'
            .'<p class="text-sm">These enabled plugins declare they don\'t support v'.e($this->pendingUpdateVersion ?? '').'. '
            .'Forcing the update anyway will automatically disable them once the new core is in place (their data and settings are preserved).</p>'
            .'<table class="w-full text-sm"><tbody>'.$rows.'</tbody></table>'
            .'</div>';

        return new HtmlString($html);
    }

    /** Primary submit of resolveIncompatiblePlugins: proceed despite the conflicts. */
    private function forceUpdateAnyway(CoreUpdateStarter $starter): void
    {
        $target = $this->pendingReleaseTarget();
        $this->incompatiblePlugins = [];
        $this->pendingUpdateVersion = null;

        if ($target === null) {
            return;
        }

        [$version, $zipUrl, $sha256, $signature] = $target;

        $starter->start(new PendingCoreUpdate($version, $zipUrl, $sha256, force: true, checksumSignature: $signature));
        $this->updating = true;
        Notification::make()
            ->title('Forced update started…')
            ->body('Incompatible plugins will be automatically disabled once the update finishes.')
            ->warning()
            ->send();
    }

    /** Extra footer action of resolveIncompatiblePlugins: remove the conflicting plugins, then update. */
    private function uninstallIncompatibleAndContinue(PluginManager $manager, CoreUpdater $updater, CoreUpdateStarter $starter): void
    {
        $target = $this->pendingReleaseTarget();
        $names = array_column($this->incompatiblePlugins, 'name');
        $this->incompatiblePlugins = [];
        $this->pendingUpdateVersion = null;

        if ($target === null) {
            return;
        }

        [$version, $zipUrl, $sha256, $signature] = $target;

        $failed = [];
        foreach ($names as $name) {
            try {
                $manager->uninstall($name);
            } catch (Throwable) {
                $failed[] = $name;
            }
        }

        if ($failed !== []) {
            Notification::make()
                ->title("Couldn't uninstall: ".implode(', ', $failed))
                ->body('The update was not started. Resolve this manually (Plugins page) and try again.')
                ->danger()
                ->send();

            return;
        }

        // Re-verify rather than trusting the uninstalls succeeded silently — the
        // update job enforces this too, but a clean recheck here gives an
        // accurate notification instead of a job that queues then fails later.
        $stillIncompatible = $updater->checkCompatibility($version);
        if ($stillIncompatible !== []) {
            $names = array_map(static fn (IncompatiblePlugin $p): string => $p->displayName, $stillIncompatible);
            Notification::make()
                ->title('Still incompatible: '.implode(', ', $names))
                ->body('The update was not started.')
                ->danger()
                ->send();

            return;
        }

        $starter->start(new PendingCoreUpdate($version, $zipUrl, $sha256, checksumSignature: $signature));
        $this->updating = true;
        Notification::make()->title('Plugins removed. Update started…')->send();
    }

    /**
     * Resolve the release the modal is about, from the recorded update check
     * rather than from anything the browser sent back.
     *
     * The archive URL and its checksum are what CoreUpdater overlays onto
     * `app/`, `bootstrap/`, and `src/Magna` — code that runs on every
     * subsequent request. They are therefore never carried in component state:
     * they are read here, at dispatch, from the row the scheduled check-in
     * wrote, and only after confirming it still describes the version the
     * admin was shown.
     *
     * @return array{0: string, 1: string, 2: string, 3: string|null}|null
     */
    private function pendingReleaseTarget(): ?array
    {
        $version = $this->pendingUpdateVersion;

        if ($version === null) {
            return null;
        }

        $core = UpdateCheck::core();

        if (
            $core?->latest_version !== $version
            || ! is_string($core->download_url)
            || ! is_string($core->download_sha256)
        ) {
            Notification::make()
                ->title("Can't update")
                ->body('The recorded release for v'.$version.' has changed or is missing its verified checksum. Re-check for updates and try again.')
                ->danger()
                ->send();

            return null;
        }

        return [$version, $core->download_url, $core->download_sha256, $core->download_sha256_signature];
    }
}
