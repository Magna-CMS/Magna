<?php

declare(strict_types=1);

namespace Magna\Admin\Concerns;

use Filament\Notifications\Notification;
use Magna\Marketplace\InstallProgress;
use Magna\Marketplace\InstallState;
use Magna\Marketplace\PluginInstaller;
use Magna\Marketplace\PluginInstallStarter;

/**
 * Watches marketplace installs and updates the Plugins page queued, and says
 * how each one ended.
 *
 * An update clicked on the Installed tab used to look like nothing at all:
 * the only poll lived in the Add New panel, the watch list was a Livewire
 * property a reload forgot, and the toasts said "installed" for updates. The
 * list now comes from InstallProgress (so a reload or a second tab keeps
 * watching), the poll runs on both tabs, and the wording follows the run.
 */
trait TracksPluginInstalls
{
    /** @var list<string> Packages currently queued/installing from the marketplace. */
    public array $installQueue = [];

    /** Livewire mount hook: resume watching whatever is still running. */
    public function mountTracksPluginInstalls(): void
    {
        $this->installQueue = InstallProgress::active();
    }

    protected function trackInstall(string $package): void
    {
        if (! in_array($package, $this->installQueue, true)) {
            $this->installQueue[] = $package;
        }
    }

    /** @return array<string, array{state: string|null, message: string, waiting_seconds: int, kind: string}> */
    protected function installProgress(): array
    {
        $progress = [];
        foreach ($this->installQueue as $package) {
            $progress[$package] = PluginInstaller::progress($package);
        }

        return $progress;
    }

    /** Poll install progress for the queued packages; notify + reload when done. */
    public function pollInstalls(PluginInstallStarter $starter): void
    {
        if ($this->installQueue === []) {
            return;
        }

        $stillGoing = [];
        $anyFinished = false;

        foreach ($this->installQueue as $package) {
            // No worker took the job. Run one stalled install per poll so a
            // long request stays bounded to a single Composer run; anything
            // else queued behind it is picked up by the following polls.
            if (! $anyFinished && $starter->isStalled($package)) {
                Notification::make()
                    ->title('No background worker is running')
                    ->body("Working on {$package} in this request instead — keep this page open until it finishes.")
                    ->warning()
                    ->send();

                $starter->installStalledInline($package);
            }

            $progress = PluginInstaller::progress($package);
            $isUpdate = $progress['kind'] === 'update';

            if ($progress['state'] === InstallState::Completed->value) {
                Notification::make()->title($package.($isUpdate ? ' updated.' : ' installed.'))->success()->send();
                InstallProgress::acknowledge($package);
                $anyFinished = true;
            } elseif ($progress['state'] === InstallState::Failed->value) {
                Notification::make()
                    ->title("Couldn't ".($isUpdate ? 'update' : 'install')." {$package}")
                    ->body($progress['message'])
                    ->danger()
                    ->send();
                InstallProgress::acknowledge($package);
                $anyFinished = true;
            } elseif ($progress['state'] === null) {
                // The progress record expired: nothing left to report on.
                InstallProgress::acknowledge($package);
            } else {
                $stillGoing[] = $package;
            }
        }

        $this->installQueue = $stillGoing;

        // When everything finishes, reload so the installed list reflects reality.
        if ($anyFinished && $this->installQueue === []) {
            $this->replaceUrl(static::getUrl());
        }
    }
}
