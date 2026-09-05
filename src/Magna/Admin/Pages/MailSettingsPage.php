<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Magna\Settings\MailConfigurator;
use Magna\Settings\MailSettings;
use Magna\Settings\MailTester;
use Magna\Settings\MailTransports;

/**
 * @property ComponentContainer $form
 */
class MailSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    // Hidden from the sidebar: consolidated into the unified SettingsPage.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Mail Settings';

    protected static ?string $title = 'Mail Settings';

    protected static ?int $navigationSort = 20;

    protected string $view = 'magna::admin.mail-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = MailSettings::get();

        $this->form->fill([
            'driver' => $settings->driver,
            'host' => $settings->host,
            'port' => $settings->port,
            'username' => $settings->username,
            // Password is secret — never pre-fill; show placeholder instead.
            'password' => null,
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'ses_key' => $settings->ses_key,
            // Secrets are never sent back to the browser; blank means "keep".
            'ses_secret' => null,
            'ses_region' => $settings->ses_region,
            'mailgun_domain' => $settings->mailgun_domain,
            'mailgun_secret' => null,
            'mailgun_endpoint' => $settings->mailgun_endpoint,
            'resend_key' => null,
            'postmark_token' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components(self::fields());
    }

    /**
     * The mail settings field group — shared with SettingsPage's "Email" tab
     * so the two surfaces can't drift out of sync with each other.
     *
     * @return list<Component>
     */
    public static function fields(): array
    {
        return [
            /*
             * Only what this server can actually build.
             *
             * The list used to be written out here, which meant it offered SES
             * and Mailgun on installs missing their packages — the exact
             * failure MailTransports was added to prevent — and never offered
             * Resend or Postmark on installs that had them. Asking
             * MailTransports keeps the picker and the transports in step, and
             * the note underneath names the package each absent driver wants.
             */
            Select::make('driver')
                ->label('Mail driver')
                ->options(MailTransports::options())
                ->helperText(self::missingDriversNote())
                // The credential fields below appear per driver, so the form
                // has to re-evaluate them as soon as the choice changes.
                ->live()
                ->required(),

            /*
             * The host and its credentials, for the one driver addressed that
             * way. Shown for SMTP alone: asking an administrator configuring
             * Resend for a mail host is asking a question with no answer, and
             * the wrong answer they invent is then the value MailConfigurator
             * reads.
             */
            TextInput::make('host')
                ->label('SMTP host')
                ->maxLength(255)
                ->visible(fn (callable $get): bool => $get('driver') === 'smtp'),

            TextInput::make('port')
                ->label('SMTP port')
                ->numeric()
                ->minValue(1)
                ->maxValue(65535)
                ->visible(fn (callable $get): bool => $get('driver') === 'smtp'),

            TextInput::make('username')
                ->label('Username')
                ->maxLength(255)
                ->nullable()
                ->visible(fn (callable $get): bool => $get('driver') === 'smtp'),

            TextInput::make('password')
                ->label('Password')
                ->password()
                ->nullable()
                ->placeholder('[secret — leave blank to keep current]')
                ->helperText('Leave blank to keep the existing password unchanged.')
                ->visible(fn (callable $get): bool => $get('driver') === 'smtp'),

            /* ------------------------------------------------ Amazon SES */

            TextInput::make('ses_key')
                ->label('Access key ID')
                ->maxLength(255)
                ->nullable()
                ->visible(fn (callable $get): bool => $get('driver') === 'ses'),

            TextInput::make('ses_secret')
                ->label('Secret access key')
                ->password()
                ->nullable()
                ->placeholder('[secret — leave blank to keep current]')
                ->visible(fn (callable $get): bool => $get('driver') === 'ses'),

            TextInput::make('ses_region')
                ->label('Region')
                ->maxLength(64)
                ->helperText('For example eu-west-1.')
                ->visible(fn (callable $get): bool => $get('driver') === 'ses'),

            /* --------------------------------------------------- Mailgun */

            TextInput::make('mailgun_domain')
                ->label('Sending domain')
                ->maxLength(255)
                ->nullable()
                ->visible(fn (callable $get): bool => $get('driver') === 'mailgun'),

            TextInput::make('mailgun_secret')
                ->label('API key')
                ->password()
                ->nullable()
                ->placeholder('[secret — leave blank to keep current]')
                ->visible(fn (callable $get): bool => $get('driver') === 'mailgun'),

            TextInput::make('mailgun_endpoint')
                ->label('API endpoint')
                ->maxLength(255)
                ->helperText('api.eu.mailgun.net for a domain created in the EU region.')
                ->visible(fn (callable $get): bool => $get('driver') === 'mailgun'),

            /* ---------------------------------------------------- Resend */

            TextInput::make('resend_key')
                ->label('API key')
                ->password()
                ->nullable()
                ->placeholder('[secret — leave blank to keep current]')
                ->helperText('From the API Keys page of your Resend dashboard.')
                ->visible(fn (callable $get): bool => $get('driver') === 'resend'),

            /* -------------------------------------------------- Postmark */

            TextInput::make('postmark_token')
                ->label('Server token')
                ->password()
                ->nullable()
                ->placeholder('[secret — leave blank to keep current]')
                ->visible(fn (callable $get): bool => $get('driver') === 'postmark'),

            /* --------------------------------------- every driver's sender */

            TextInput::make('from_address')
                ->label('From address')
                ->email()
                ->maxLength(255),

            TextInput::make('from_name')
                ->label('From name')
                ->maxLength(255),
        ];
    }

    /**
     * Folds submitted form state onto the stored mail settings.
     *
     * Shared with SettingsPage's "Email" tab, which draws the same fields from
     * {@see self::fields()} and used to write them back with a second copy of
     * this logic. The two drifted exactly as such pairs do: the unified page —
     * the one an administrator actually reaches, since this one is hidden from
     * the navigation — never learned about the Resend and Postmark tokens, and
     * still read hidden fields as empty values. One writer, both surfaces.
     *
     * @param  array<string, mixed>  $data
     */
    public static function persist(array $data): MailSettings
    {
        $settings = MailSettings::get();
        $settings->driver = is_string($data['driver'] ?? null) ? $data['driver'] : 'smtp';

        /*
         * Only the fields the chosen driver actually showed.
         *
         * A hidden field is absent from the form state, not blank in it — so
         * reading it unconditionally is how configuring Resend wiped the SMTP
         * host and port that an administrator would want back the moment they
         * switched the driver again. Present means "the form asked, and this is
         * the answer"; absent means "not this driver's business, leave it".
         */
        if (array_key_exists('host', $data)) {
            $settings->host = (string) ($data['host'] ?? '');
        }

        if (array_key_exists('port', $data)) {
            $settings->port = (int) $data['port'];
        }

        if (array_key_exists('username', $data)) {
            $settings->username = $data['username'] ?: null;
        }

        if (filled($data['from_address'] ?? null)) {
            $settings->from_address = (string) $data['from_address'];
        }

        if (array_key_exists('from_name', $data)) {
            $settings->from_name = (string) ($data['from_name'] ?? '');
        }

        if (array_key_exists('ses_key', $data)) {
            $settings->ses_key = $data['ses_key'] ?: null;
        }

        if (array_key_exists('ses_region', $data)) {
            $settings->ses_region = (string) ($data['ses_region'] ?? 'us-east-1');
        }

        if (array_key_exists('mailgun_domain', $data)) {
            $settings->mailgun_domain = $data['mailgun_domain'] ?: null;
        }

        if (array_key_exists('mailgun_endpoint', $data)) {
            $settings->mailgun_endpoint = (string) ($data['mailgun_endpoint'] ?? 'api.mailgun.net');
        }

        /*
         * Secrets are never sent back to the browser, so the field arrives
         * blank on every load. Blank therefore has to mean "keep what is
         * stored" — which is what the placeholder promises — and only a value
         * typed now overwrites one.
         */
        foreach (['password', 'ses_secret', 'mailgun_secret', 'resend_key', 'postmark_token'] as $secret) {
            if (filled($data[$secret] ?? null)) {
                $settings->{$secret} = (string) $data[$secret];
            }
        }

        return $settings;
    }

    /**
     * What is missing, and what would bring it back.
     *
     * A driver absent from the picker looks like a feature that was removed.
     * Naming the one package each needs turns it into a decision the
     * administrator can act on.
     */
    private static function missingDriversNote(): ?string
    {
        $available = MailTransports::options();

        $missing = [];

        foreach (['ses' => 'Amazon SES', 'mailgun' => 'Mailgun', 'postmark' => 'Postmark', 'resend' => 'Resend'] as $driver => $label) {
            if (! array_key_exists($driver, $available)) {
                $missing[] = sprintf('%s (%s)', $label, MailTransports::packageFor($driver));
            }
        }

        return $missing === []
            ? null
            : 'Not installed on this server: '.implode(', ', $missing).'. Install the package to use one.';
    }

    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();

        $settings = self::persist($data);

        $settings->save();

        // Fold the new values over config immediately. Without this the mailer
        // keeps whatever was read at boot until the next request, so a test
        // sent straight after saving would exercise the old settings.
        app(MailConfigurator::class)->apply();

        Notification::make()
            ->title('Mail settings saved.')
            ->success()
            ->send();
    }

    /**
     * Sends a test message to the signed-in administrator.
     *
     * To themselves rather than to an address they type: the point is to prove
     * the transport works, and a form that accepts any recipient turns the
     * panel into something that can be used to send mail to strangers.
     */
    public function sendTest(): void
    {
        /*
         * Resolved here rather than type-hinted on the action's closure.
         * Filament evaluates that closure with its own injection rules, and
         * the parameter never arrived — the button ran, returned 200 and did
         * nothing at all, which is worse than a button that fails, because
         * the one thing it exists to report is whether mail works.
         */
        $tester = app(MailTester::class);

        $user = auth()->user();
        $address = $user?->getAttribute('email');

        if (! is_string($address) || $address === '') {
            Notification::make()
                ->title('Your account has no email address to send a test to.')
                ->danger()
                ->send();

            return;
        }

        $result = $tester->send($address);

        Notification::make()
            ->title($result['ok'] ? 'Test email sent.' : 'The test email could not be sent.')
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            // The transport's own error can be long, and it is the one thing
            // worth reading here.
            ->persistent()
            ->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save settings')
                ->action(fn () => $this->save()),

            Action::make('sendTest')
                ->label('Send test email')
                ->color('gray')
                ->action(fn () => $this->sendTest()),
        ];
    }
}
