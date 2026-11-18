<?php

declare(strict_types=1);

namespace Magna\Admin\Concerns;

use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
use Magna\Themes\ThemeContent;
use Magna\Themes\ThemeManager;
use Throwable;

/**
 * Taking a theme's demo content back out — the Themes page's answer to "I
 * switched themes and the old one's demo pages are still everywhere".
 *
 * Three ways in, one dialog: the card's "Remove demo content" button, the
 * Remove button of a theme whose demo is on the site, and activating another
 * theme while the active one's demo is on the site. The dialog lists what
 * would go and what would stay (and why) before anything changes, and every
 * removal is untouched-only (see ThemeContentRemover).
 *
 * A switch runs in a fixed order — remove the old demo, activate, import the
 * new demo — so the new theme's pages are not skipped for slugs the old
 * demo still holds.
 *
 * Services arrive by Livewire method injection: the page's container
 * lookups are pinned by the architecture ratchet.
 */
trait ManagesThemeDemoContent
{
    /** 'remove-demo', 'remove-theme' or 'switch'; null while the dialog is closed. */
    #[Locked]
    public ?string $demoMode = null;

    /** The theme whose demo content the dialog is about. */
    #[Locked]
    public ?string $demoTheme = null;

    /** The theme a switch activates. */
    #[Locked]
    public ?string $demoTarget = null;

    /** @var array{remove: list<array{key: string, label: string}>, keep: list<array{key: string, label: string, reason: string}>} */
    #[Locked]
    public array $demoPreview = ['remove' => [], 'keep' => []];

    /** The dialog's choices; the card button always removes. */
    public bool $demoRemove = false;

    public bool $demoImport = false;

    public function askRemoveDemo(string $name, ThemeManager $themes): void
    {
        $this->openDemoDialog('remove-demo', $name, null, $themes);
    }

    public function askRemoveTheme(string $name, ThemeManager $themes): void
    {
        $this->openDemoDialog('remove-theme', $name, null, $themes);
    }

    public function askActivate(string $name, ThemeManager $themes): void
    {
        $current = $themes->active()?->name;

        if ($current === null || $current === $name || ! $themes->content()->onSite($current)) {
            $this->activate($name);

            return;
        }

        $this->openDemoDialog('switch', $current, $name, $themes);
    }

    public function closeDemoDialog(): void
    {
        $this->demoMode = $this->demoTheme = $this->demoTarget = null;
        $this->demoPreview = ['remove' => [], 'keep' => []];
        $this->demoRemove = $this->demoImport = false;
    }

    public function confirmDemoDialog(ThemeManager $themes): void
    {
        $mode = $this->demoMode;
        $theme = $this->demoTheme;
        $target = $this->demoTarget;
        $removeDemo = $mode === 'remove-demo' || $this->demoRemove;
        $importDemo = $this->demoImport;
        $this->closeDemoDialog();

        if ($mode === null || $theme === null) {
            return;
        }

        match ($mode) {
            'remove-theme' => $this->removeTheme($themes, $theme, $removeDemo),
            'switch' => $target === null ? null : $this->switchTheme($themes, $theme, $target, $removeDemo, $importDemo),
            default => $this->removeDemoOnly($themes, $theme),
        };
    }

    /** Remove a theme with no demo content of its own on the site — the card's plain Remove. */
    public function remove(string $name, ThemeManager $themes): void
    {
        $this->removeTheme($themes, $name, false);
    }

    private function openDemoDialog(string $mode, string $theme, ?string $target, ThemeManager $themes): void
    {
        try {
            $preview = $themes->content()->removalPreview($theme);
        } catch (Throwable $e) {
            Notification::make()->title('Could not read the demo content')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->demoMode = $mode;
        $this->demoTheme = $theme;
        $this->demoTarget = $target;
        $this->demoPreview = $preview;
        $this->demoRemove = false;
        $this->demoImport = false;
    }

    private function removeDemoOnly(ThemeManager $themes, string $theme): void
    {
        try {
            $totals = $themes->content()->removeDemo($theme, $this->demoActorId());
        } catch (Throwable $e) {
            Notification::make()->title('Demo content was not removed')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Demo content removed')->body(ThemeContent::removalSummary($totals))->success()->send();
    }

    private function removeTheme(ThemeManager $themes, string $theme, bool $removeDemo): void
    {
        try {
            $totals = $themes->remove($theme, $removeDemo, $this->demoActorId());
        } catch (Throwable $e) {
            Notification::make()->title('Could not remove that theme')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Theme removed.')
            ->body($removeDemo ? 'Its demo content: '.ThemeContent::removalSummary($totals) : null)
            ->success()
            ->send();
    }

    private function switchTheme(ThemeManager $themes, string $from, string $to, bool $removeDemo, bool $importDemo): void
    {
        $lines = [];

        if ($removeDemo) {
            try {
                $lines[] = 'Previous demo content: '.ThemeContent::removalSummary($themes->content()->removeDemo($from, $this->demoActorId()));
            } catch (Throwable $e) {
                Notification::make()->title('Nothing changed')->body('The previous theme\'s demo content could not be removed, so the theme was not switched: '.$e->getMessage())->danger()->send();

                return;
            }
        }

        try {
            $theme = $themes->activate($to);
        } catch (Throwable $e) {
            Notification::make()->title('Could not activate that theme')->body(implode(' ', [...$lines, $e->getMessage()]))->danger()->send();

            return;
        }

        if ($importDemo) {
            try {
                $lines[] = 'New demo content: '.ThemeContent::summary($themes->content()->import($to, $this->demoActorId()));
            } catch (Throwable $e) {
                $lines[] = 'Its demo content was not imported: '.$e->getMessage();
            }
        }

        Notification::make()
            ->title($theme->displayName.' is now the active theme.')
            ->body($lines === [] ? null : implode(' ', $lines))
            ->success()
            ->send();
    }

    private function demoActorId(): ?string
    {
        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }
}
