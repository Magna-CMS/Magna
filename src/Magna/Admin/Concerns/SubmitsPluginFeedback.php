<?php

declare(strict_types=1);

namespace Magna\Admin\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Magna\Marketplace\MarketplaceClient;

/**
 * Reviewing and reporting a marketplace plugin: the two feedback sheets the
 * Plugins page offers, and the state they share.
 *
 * Extracted from PluginsPage for the reason its pin in ArchitectureTest
 * records — the page is held shrink-only and had reached its ceiling, so the
 * next feature had nowhere to go. This is one concern (telling the
 * marketplace something about a plugin) with one piece of state, which is
 * what made it the piece to lift; the same move SystemInfoPage made into
 * Admin/Concerns when it outgrew its own pin.
 *
 * The client arrives as a closure parameter rather than by service location:
 * Filament resolves action-closure arguments from the container, and the
 * per-file service-location ratchet is not something to spend on a
 * dependency that can simply be asked for. (That ratchet counts the literal
 * call text, so naming the helper here would register as a call itself —
 * which is why this paragraph talks around it.)
 *
 * @phpstan-require-extends Page
 */
trait SubmitsPluginFeedback
{
    /** The marketplace package a review/report sheet is currently targeting. */
    public string $feedbackPackage = '';

    public function requestReview(string $name): void
    {
        if (! $this->requireConnectedAccount('Reviews and reports are tied to your Magna Account so they can be traced back to a real install.')) {
            return;
        }

        $this->feedbackPackage = $name;
        $this->mountAction('review');
    }

    public function requestReport(string $name): void
    {
        if (! $this->requireConnectedAccount('Reviews and reports are tied to your Magna Account so they can be traced back to a real install.')) {
            return;
        }

        $this->feedbackPackage = $name;
        $this->mountAction('report');
    }

    /** Write-a-review sheet: star rating + optional name and text, sent to the marketplace. */
    public function reviewAction(): Action
    {
        return Action::make('review')
            ->modalHeading(fn (): string => 'Review '.$this->feedbackDisplayName())
            ->modalIcon('heroicon-o-star')
            ->modalSubmitActionLabel('Submit review')
            ->form([
                Select::make('stars')
                    ->label('Your rating')
                    ->options([5 => '★★★★★', 4 => '★★★★☆', 3 => '★★★☆☆', 2 => '★★☆☆☆', 1 => '★☆☆☆☆'])
                    ->default(5)
                    ->required()
                    ->native(false),
                TextInput::make('author')->label('Your name')->maxLength(255)->placeholder('Optional'),
                Textarea::make('review')->label('Review')->rows(4)->maxLength(2000)->placeholder('What did you think of this plugin?'),
            ])
            ->action(function (array $data, MarketplaceClient $marketplace): void {
                $package = $this->feedbackPackage;
                $this->feedbackPackage = '';
                if ($package === '') {
                    return;
                }

                $ok = $marketplace->submitReview(
                    $package,
                    (int) $data['stars'],
                    $data['review'] ?? null,
                    $data['author'] ?? null,
                );

                $ok
                    ? Notification::make()->title('Thanks for your review!')->success()->send()
                    : Notification::make()->title("Couldn't submit your review")->body('The marketplace could not be reached. Please try again later.')->danger()->send();
            });
    }

    /** Report-a-plugin sheet: a reason + optional details, sent to the marketplace operators. */
    public function reportAction(): Action
    {
        return Action::make('report')
            ->modalHeading(fn (): string => 'Report '.$this->feedbackDisplayName())
            ->modalIcon('heroicon-o-flag')
            ->modalIconColor('danger')
            ->modalSubmitActionLabel('Submit report')
            ->color('danger')
            ->form([
                Select::make('reason')
                    ->label('Reason')
                    ->options([
                        'spam' => 'Spam or misleading',
                        'malicious' => 'Malicious or unsafe',
                        'broken' => "Doesn't work",
                        'copyright' => 'Copyright violation',
                        'other' => 'Other',
                    ])
                    ->required()
                    ->native(false),
                Textarea::make('details')->label('Details')->rows(3)->maxLength(2000)->placeholder('Optional — tell us what’s wrong.'),
            ])
            ->action(function (array $data, MarketplaceClient $marketplace): void {
                $package = $this->feedbackPackage;
                $this->feedbackPackage = '';
                if ($package === '') {
                    return;
                }

                $ok = $marketplace->reportPlugin(
                    $package,
                    (string) $data['reason'],
                    $data['details'] ?? null,
                );

                $ok
                    ? Notification::make()->title('Reported — thanks for flagging this.')->success()->send()
                    : Notification::make()->title("Couldn't submit the report")->body('The marketplace could not be reached. Please try again later.')->danger()->send();
            });
    }

    /**
     * The plugin's display name when the catalog knows it, falling back to the
     * package — a modal heading must never read "Review ".
     */
    private function feedbackDisplayName(): string
    {
        $plugin = collect($this->available)->firstWhere('name', $this->feedbackPackage);
        $name = is_array($plugin) ? ($plugin['display_name'] ?? null) : null;

        return is_string($name) ? $name : $this->feedbackPackage;
    }
}
