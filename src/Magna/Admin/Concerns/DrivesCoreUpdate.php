<?php

declare(strict_types=1);

namespace Magna\Admin\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Magna\MagnaServiceProvider;
use Magna\Updater\CoreUpdateProgress;
use Magna\Updater\CoreUpdater;
use Magna\Updater\CoreUpdateStarter;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\Footprint\FootprintCheck;
use Magna\Updater\IncompatiblePlugin;
use Magna\Updater\PendingCoreUpdate;
use Magna\Updater\Preflight\PreflightProblem;
use Magna\Updater\Preflight\UpdatePreflight;
use Magna\Updater\Run\RunOutcome;
use Magna\Updater\Run\UpdateResumer;
use Magna\Updater\UpdateCheck;
use Magna\Updater\UpdateMode;

/**
 * "Update now", "Repair core files", and the progress poll behind both — the
 * poll also carries a queued apply out itself when no worker takes it, and
 * finishes a switched update when this request is the first process on the
 * new code.
 *
 * On a Filament page rather than in a service because it is Livewire state
 * (`$updating`) and Livewire callbacks; extracted from SystemInfoPage so the
 * page stays the size its architecture pin allows. Dependencies arrive by
 * method injection — Livewire resolves typed parameters on actions and
 * Filament on closures — so nothing here reaches for the container.
 */
trait DrivesCoreUpdate
{
    /** True while an "Update Now" or "Repair" apply is in progress, so the page polls CoreUpdater::progress(). */
    public bool $updating = false;

    public function updateNowAction(): Action
    {
        return Action::make('updateNow')
            ->label(fn (): string => 'Update to v'.(UpdateCheck::core()->latest_version ?? ''))
            ->icon('heroicon-o-rocket-launch')
            ->color('warning')
            ->visible(fn (): bool => (UpdateCheck::core()->update_available ?? false) && ! $this->updating)
            ->authorize(fn (): bool => auth()->user()?->can('settings.manage') ?? false)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Update Magna CMS to v'.(UpdateCheck::core()->latest_version ?? '').'?')
            ->modalDescription('The site goes into maintenance mode during the update. The release is staged beside the live files and switched in; the previous files stay until the new release has proven it boots.')
            ->modalSubmitActionLabel('Update now')
            ->action(function (CoreUpdater $updater, CoreUpdateStarter $starter, UpdatePreflight $preflight): void {
                $core = UpdateCheck::core();
                if ($core?->latest_version === null || $core->download_url === null || $core->download_sha256 === null) {
                    Notification::make()->title("Can't update")->body('No verified release archive is available for the latest version yet.')->danger()->send();

                    return;
                }

                // What Update Manager said the release needs, refused here
                // rather than after a download. The archive's own manifest is
                // enforced regardless once it is on disk.
                $hints = $preflight->hintProblems($core->requires_php, $core->min_upgrade_from);
                if ($hints !== []) {
                    Notification::make()->title("Can't update to v{$core->latest_version}")->body(implode(' ', array_map(static fn (PreflightProblem $p): string => $p->message, $hints)))->danger()->send();

                    return;
                }

                $incompatible = $updater->checkCompatibility($core->latest_version);
                if ($incompatible !== []) {
                    $this->pendingUpdateVersion = $core->latest_version;
                    $this->incompatiblePlugins = array_map(
                        static fn (IncompatiblePlugin $p): array => $p->toArray(),
                        $incompatible,
                    );
                    $this->replaceMountedAction('resolveIncompatiblePlugins');

                    return;
                }

                $starter->start(new PendingCoreUpdate(
                    version: $core->latest_version,
                    zipUrl: $core->download_url,
                    expectedSha256: $core->download_sha256,
                    checksumSignature: $core->download_sha256_signature,
                ));
                $this->updating = true;
                Notification::make()->title('Update started…')->send();
            });
    }

    /**
     * Re-apply the running release from the archive Update Manager publishes
     * for it. Offered only to a site whose running version was never recorded
     * as delivered — one an older updater brought here — and only once the
     * hub has published that archive; until then the shell command is the way.
     */
    public function repairCoreAction(): Action
    {
        return Action::make('repairCore')
            ->label('Repair core files')
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('warning')
            ->visible(fn (FootprintCheck $check): bool => ! $this->updating
                && $check->deliveredByOlderUpdater()
                && (UpdateCheck::core()?->hasRepairGrant() ?? false))
            ->authorize(fn (): bool => auth()->user()?->can('settings.manage') ?? false)
            ->requiresConfirmation()
            ->modalHeading('Repair Magna CMS v'.MagnaServiceProvider::VERSION.'?')
            ->modalDescription('Re-applies the release this site already runs from its verified archive, so everything the release ships is present and recorded. The site goes into maintenance mode briefly; nothing of yours is touched.')
            ->modalSubmitActionLabel('Repair')
            ->action(function (CoreUpdateStarter $starter): void {
                $core = UpdateCheck::core();

                if ($core === null || ! $core->hasRepairGrant()) {
                    Notification::make()->title("Can't repair")->body('Update Manager has not published a verified archive for this version yet. Use `php artisan magna:core:repair` with the archive and its checksum instead.')->danger()->send();

                    return;
                }

                $starter->start(new PendingCoreUpdate(
                    version: MagnaServiceProvider::VERSION,
                    zipUrl: (string) $core->installed_download_url,
                    expectedSha256: $core->installed_download_sha256,
                    checksumSignature: $core->installed_download_sha256_signature,
                    mode: UpdateMode::Repair->value,
                ));
                $this->updating = true;
                Notification::make()->title('Repair started…')->send();
            });
    }

    /** Poll the running core update; notify + reload the page when it finishes. */
    public function pollCoreUpdate(CoreUpdateStarter $starter, UpdateResumer $resumer): void
    {
        if (! $this->updating) {
            return;
        }

        // Reading progress is fine for anyone who can see this page
        // (settings.view), but the fallbacks *perform* the apply inside this
        // request — that is the same privilege "Update Now" needs, and
        // $updating is a plain public property a client can flip. So they
        // are authorized separately here rather than inherited from page
        // access.
        $canManage = auth()->user()?->can('settings.manage') ?? false;

        if ($starter->isStalled() && $canManage) {
            $this->applyStalledUpdate($starter);

            return;
        }

        // A switched update waits for a process on the new release, and the
        // admin's own poll is the one process a host without a worker or
        // cron is sure to have. If this request still runs the old code, a
        // reload is what gets it onto the new one.
        $pending = $resumer->pending();

        if ($pending !== null && $pending->state()->isPostSwitch()) {
            // The journal outlives the cache entry the bar reads: a cache flush
            // during the switch must not blank the panel while the run goes on.
            if (CoreUpdater::progress()['state'] === null) {
                CoreUpdateProgress::set(CoreUpdateState::Running, 'Finishing v'.($pending->string('to') ?? '').' under the new release…', 80, $pending->string('to'));
            }

            if ($canManage) {
                $outcome = $resumer->resume();

                if ($outcome->is(RunOutcome::STALE_CODE)) {
                    $this->js('setTimeout(function(){ window.location.reload(); }, 1500)');

                    return;
                }
            }
        }

        $this->reportUpdateOutcome(CoreUpdater::progress());
    }

    /**
     * No worker took the job, so this request applies the update itself.
     *
     * Deliberately synchronous: the admin is already watching a progress panel,
     * the site is in maintenance mode for the duration, and the alternative is
     * an update that never runs at all.
     */
    private function applyStalledUpdate(CoreUpdateStarter $starter): void
    {
        Notification::make()
            ->title('No background worker is running')
            ->body('Applying the update in this request instead — keep this page open until it finishes.')
            ->warning()
            ->send();

        if ($starter->applyStalledInline() === null) {
            return;
        }

        $this->reportUpdateOutcome(CoreUpdater::progress());
    }

    /** @param  array{state: string|null, message: string, percent: int, version: string|null, log: list<array{message: string, percent: int}>, waiting_seconds: int}  $progress */
    private function reportUpdateOutcome(array $progress): void
    {
        if ($progress['state'] === CoreUpdateState::Completed->value) {
            $this->updating = false;
            CoreUpdateProgress::clearPending();
            Notification::make()->title('Update complete')->body($progress['message'])->success()->send();
            $this->js('setTimeout(function(){ window.location.reload(); }, 800)');
        } elseif ($progress['state'] === CoreUpdateState::Failed->value) {
            $this->updating = false;
            CoreUpdateProgress::clearPending();
            Notification::make()->title("Update didn't complete")->body($progress['message'])->danger()->send();
        }
    }
}
