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
            Select::make('driver')
                ->label('Mail driver')
                ->options([
                    'smtp' => 'SMTP',
                    'sendmail' => 'Sendmail',
                    'log' => 'Log (development)',
                    'array' => 'Array (testing)',
                    'ses' => 'Amazon SES',
                    'mailgun' => 'Mailgun',
                ])
                ->required(),

            TextInput::make('host')
                ->label('SMTP host')
                ->maxLength(255),

            TextInput::make('port')
                ->label('SMTP port')
                ->numeric()
                ->minValue(1)
                ->maxValue(65535),

            TextInput::make('username')
                ->label('Username')
                ->maxLength(255)
                ->nullable(),

            TextInput::make('password')
                ->label('Password')
                ->password()
                ->nullable()
                ->placeholder('[secret — leave blank to keep current]')
                ->helperText('Leave blank to keep the existing password unchanged.'),

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

        $settings = MailSettings::get();
        $settings->driver = $data['driver'];
        // host and from_name are optional inputs → null when cleared; coerce to
        // string since the settings properties are non-nullable.
        $settings->host = (string) ($data['host'] ?? '');
        $settings->port = (int) $data['port'];
        $settings->username = $data['username'] ?: null;
        $settings->from_address = $data['from_address'] ?? $settings->from_address;
        $settings->from_name = (string) ($data['from_name'] ?? '');

        $settings->ses_key = ($data['ses_key'] ?? null) ?: null;
        $settings->ses_region = (string) ($data['ses_region'] ?? 'us-east-1');
        $settings->mailgun_domain = ($data['mailgun_domain'] ?? null) ?: null;
        $settings->mailgun_endpoint = (string) ($data['mailgun_endpoint'] ?? 'api.mailgun.net');

        // Only overwrite a secret if the user supplied a new value. Blank means
        // "leave it alone", which is what the placeholder promises.
        if (filled($data['password'])) {
            $settings->password = $data['password'];
        }

        if (filled($data['ses_secret'] ?? null)) {
            $settings->ses_secret = (string) $data['ses_secret'];
        }

        if (filled($data['mailgun_secret'] ?? null)) {
            $settings->mailgun_secret = (string) $data['mailgun_secret'];
        }

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
    public function sendTest(MailTester $tester): void
    {
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
                ->action(fn (MailTester $tester) => $this->sendTest($tester)),
        ];
    }
}
