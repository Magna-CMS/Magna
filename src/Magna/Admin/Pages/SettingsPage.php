<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Magna\Admin\Support\S3CredentialFields;
use Magna\Plugins\PluginRecord;
use Magna\Settings\ContentSettings;
use Magna\Settings\GeneralSettings;
use Magna\Settings\GeneralSettingsPersister;
use Magna\Settings\LocalizationSettings;
use Magna\Settings\MailConfigurator;
use Magna\Settings\MailSettings;
use Magna\Settings\MediaSettings;
use Magna\Settings\PerformanceSettings;
use Magna\Settings\SecuritySettings;
use Magna\Settings\StorageSettings;
use Magna\Settings\UrlSettings;

/**
 * Unified settings page: every settings group lives on one scrollable page,
 * split into anchored sections. This replaces the previous one-page-per-group
 * navigation.
 *
 * anchor() stamps an id on each Section so a link can land on one. A
 * `sections()` method once listed the same nine again for a sticky sub-nav the
 * view never grew; it is gone — if that sub-nav arrives, it reads the anchors.
 *
 * @property Schema $form
 */
class SettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'All Settings';

    protected static ?string $title = 'Settings';

    protected static ?string $slug = 'settings';

    protected static ?int $navigationSort = 0;

    protected string $view = 'magna::admin.settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    private function magnaPagesInstalled(): bool
    {
        return PluginRecord::query()
            ->where('name', 'magna-cms/pages')
            ->where('enabled', true)
            ->exists();
    }

    public function mount(): void
    {
        $general = GeneralSettings::get();
        $localization = LocalizationSettings::get();
        $content = ContentSettings::get();
        $media = MediaSettings::get();
        $mail = MailSettings::get();
        $storage = StorageSettings::get();
        $url = UrlSettings::get();
        $security = SecuritySettings::get();
        $performance = PerformanceSettings::get();

        $this->form->fill([
            // General
            'site_name' => $general->site_name,
            'site_tagline' => $general->site_tagline,
            'registration_enabled' => $general->registration_enabled,
            'timezone' => $general->timezone,
            'default_locale' => $general->default_locale,
            'date_format' => $general->date_format,
            'time_format' => $general->time_format,
            'first_day_of_week' => $general->first_day_of_week,
            'currency' => $general->currency,
            // Localization
            'available_locales' => $localization->available_locales,
            'fallback_locale' => $localization->fallback_locale,
            'rtl_locales' => $localization->rtl_locales,
            // Content
            'default_status' => $content->default_status,
            'revision_limit' => $content->revision_limit,
            'autosave_interval' => $content->autosave_interval,
            // Media
            'max_image_upload_bytes' => $media->max_image_upload_bytes,
            'max_svg_upload_bytes' => $media->max_svg_upload_bytes,
            'max_document_upload_bytes' => $media->max_document_upload_bytes,
            'default_image_quality' => $media->default_image_quality,
            'webp_enabled' => $media->webp_enabled,
            'avif_enabled' => $media->avif_enabled,
            // Mail
            'driver' => $mail->driver,
            'host' => $mail->host,
            'port' => $mail->port,
            'username' => $mail->username,
            'from_address' => $mail->from_address,
            'from_name' => $mail->from_name,
            'ses_key' => $mail->ses_key,
            'ses_secret' => null,
            'ses_region' => $mail->ses_region,
            'mailgun_domain' => $mail->mailgun_domain,
            'mailgun_secret' => null,
            'mailgun_endpoint' => $mail->mailgun_endpoint,
            // Secrets are never sent back to the browser; blank means "keep".
            'resend_key' => null,
            'postmark_token' => null,
            // Storage
            'disk' => $storage->disk,
            's3_key' => $storage->s3_key,
            's3_bucket' => $storage->s3_bucket,
            's3_region' => $storage->s3_region,
            's3_url' => $storage->s3_url,
            // URLs
            'cdn_url' => $url->cdn_url,
            'frontend_url' => $url->frontend_url,
            'preview_base_url' => $url->preview_base_url,
            // Security
            'force_https' => $security->force_https,
            'require_email_verification' => $security->require_email_verification,
            'session_lifetime' => $security->session_lifetime,
            // Performance
            'cache_driver' => $performance->cache_driver,
            'queue_connection' => $performance->queue_connection,
            'redis_host' => $performance->redis_host,
            'redis_port' => $performance->redis_port,
            'redis_password' => null,
            'redis_database' => $performance->redis_database,
            'octane_server' => $performance->octane_server,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                $this->anchor('general', 'General', [
                    // Read by core's logo block and every page title on a
                    // rendered site — until these had a form, a published site
                    // called itself "Magna CMS" and only tinker could argue.
                    TextInput::make('site_name')->label('Site name')->required()->maxLength(255)->placeholder('My Site')->helperText('Shown in page titles, the logo block and anywhere the site names itself.'),
                    TextInput::make('site_tagline')->label('Tagline')->maxLength(255)->placeholder('A short line about the site')->helperText('A one-line description themes and metadata can use. Optional.'),
                    Toggle::make('registration_enabled')->label('Allow public registration')->helperText('When disabled, only admins can create new user accounts.')->inline(false),
                    Select::make('timezone')->label('Default timezone')->required()->searchable()->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list())),
                    TextInput::make('default_locale')->label('Default language')->required()->maxLength(10)->placeholder('en')->helperText('BCP 47 locale code (e.g. en, fr, de).'),
                    Select::make('date_format')->label('Default date format')->required()->options([
                        'Y-m-d' => 'ISO 8601 (2025-01-31)', 'd/m/Y' => 'DD/MM/YYYY (31/01/2025)', 'm/d/Y' => 'MM/DD/YYYY (01/31/2025)',
                        'd.m.Y' => 'DD.MM.YYYY (31.01.2025)', 'M j, Y' => 'Jan 31, 2025', 'j F Y' => '31 January 2025',
                    ]),
                    Select::make('time_format')->label('Default time format')->required()->options(['H:i' => '24-hour (14:30)', 'g:i A' => '12-hour (2:30 PM)']),
                    Select::make('first_day_of_week')->label('First day of week')->required()->options([0 => 'Sunday', 1 => 'Monday', 6 => 'Saturday']),
                    TextInput::make('currency')->label('Default currency')->maxLength(10)->placeholder('USD')->helperText('ISO 4217 currency code. Optional.')->nullable(),
                ]),

                $this->anchor('localization', 'Localization', [
                    TagsInput::make('available_locales')->label('Available locales')->placeholder('Add locale code (e.g. en, fr, de)')->helperText('Locale codes the site supports. The delivery API accepts ?locale= values from this list.'),
                    TextInput::make('fallback_locale')->label('Fallback locale')->required()->maxLength(10)->placeholder('en')->helperText('Used when requested content is missing in the requested locale.'),
                    TagsInput::make('rtl_locales')->label('RTL locales')->placeholder('Add RTL locale code (e.g. ar, he)')->helperText('Locale codes that use right-to-left text direction.'),
                ]),

                $this->anchor('content', 'Content', [
                    Select::make('default_status')->label('Default entry status')->required()->options(['draft' => 'Draft', 'published' => 'Published'])->helperText('Status applied to newly created entries.'),
                    TextInput::make('revision_limit')->label('Revision limit per entry')->numeric()->required()->minValue(1)->maxValue(500)->helperText('Older revisions are pruned when this limit is exceeded.'),
                    TextInput::make('autosave_interval')->label('Autosave interval (seconds)')->numeric()->required()->minValue(15)->maxValue(600)->helperText('How often the block editor autosaves a draft.'),
                ]),

                $this->anchor('media', 'Media', [
                    TextInput::make('max_image_upload_bytes')->label('Max image upload (bytes)')->numeric()->required()->minValue(1_048_576)->helperText('JPEG, PNG, GIF, WebP, AVIF. Default: 20971520 (20 MB).'),
                    TextInput::make('max_svg_upload_bytes')->label('Max SVG upload (bytes)')->numeric()->required()->minValue(1_024)->helperText('Default: 2097152 (2 MB).'),
                    TextInput::make('max_document_upload_bytes')->label('Max document upload (bytes)')->numeric()->required()->minValue(1_048_576)->helperText('PDFs and other documents. Default: 52428800 (50 MB).'),
                    TextInput::make('default_image_quality')->label('Image encoding quality (1–100)')->numeric()->required()->minValue(1)->maxValue(100),
                    Toggle::make('webp_enabled')->label('Generate WebP conversions')->inline(false),
                    Toggle::make('avif_enabled')->label('Generate AVIF conversions')->helperText('Best-effort; requires GD with libavif.')->inline(false),
                ]),

                $this->anchor('email', 'Email', MailSettingsPage::fields()),

                $this->anchor('storage', 'Storage', [
                    Select::make('disk')->label('Storage driver')->required()->live()->options([
                        'local' => 'Local filesystem', 'public' => 'Public (local, web-accessible)', 's3' => 'Amazon S3', 's3-like' => 'S3-compatible (R2, MinIO, etc.)',
                    ]),
                    ...S3CredentialFields::make(fn (callable $get): bool => in_array($get('disk'), ['s3', 's3-like'], true)),
                ]),

                $this->anchor('urls', 'URLs & Frontend', $this->urlComponents()),

                $this->anchor('security', 'Security', [
                    Toggle::make('force_https')->label('Force HTTPS')->helperText('Redirect all HTTP requests to HTTPS. Only enable with an SSL certificate in place.')->inline(false),
                    Toggle::make('require_email_verification')->label('Require email verification on registration')->inline(false),
                    TextInput::make('session_lifetime')->label('Session lifetime (minutes)')->numeric()->required()->minValue(1)->maxValue(525600)->helperText('How long an idle web session stays alive. Takes effect on the next request.'),
                ]),

                $this->anchor('performance', 'Performance', [
                    Select::make('cache_driver')->label('Cache driver')->required()->live()->options([
                        'file' => 'File (local disk)', 'database' => 'Database', 'redis' => 'Redis',
                    ])->helperText('Redis avoids a SQL round-trip on every cache read/write — recommended once a Redis server is reachable.'),
                    Select::make('queue_connection')->label('Queue connection')->required()->live()->options([
                        'sync' => 'Sync (runs immediately, no background worker)', 'database' => 'Database', 'redis' => 'Redis',
                    ])->helperText('Media thumbnail generation and other background jobs use this connection. "Sync" blocks the request until the job finishes.'),
                    TextInput::make('redis_host')->label('Redis host')->maxLength(255)->visible(fn (callable $get): bool => in_array('redis', [$get('cache_driver'), $get('queue_connection')], true)),
                    TextInput::make('redis_port')->label('Redis port')->numeric()->minValue(1)->maxValue(65535)->visible(fn (callable $get): bool => in_array('redis', [$get('cache_driver'), $get('queue_connection')], true)),
                    TextInput::make('redis_password')->label('Redis password')->password()->nullable()->placeholder('[secret — leave blank to keep current]')->helperText('Leave blank to keep the existing password unchanged.')->visible(fn (callable $get): bool => in_array('redis', [$get('cache_driver'), $get('queue_connection')], true)),
                    TextInput::make('redis_database')->label('Redis database index')->numeric()->minValue(0)->maxValue(15)->visible(fn (callable $get): bool => in_array('redis', [$get('cache_driver'), $get('queue_connection')], true)),
                    PerformanceSettingsPage::octaneStatusPlaceholder(),
                    Select::make('octane_server')->label('Octane server')->required()->options([
                        'frankenphp' => 'FrankenPHP (recommended)', 'swoole' => 'Swoole', 'roadrunner' => 'RoadRunner',
                    ])->helperText('Only takes effect when the app is started with "php artisan octane:start" — see System Info for whether Octane is currently running.'),
                ], headerActions: [PerformanceSettingsPage::learnMoreAction()]),

                SchemaActions::make([
                    Action::make('saveBottom')->label('Save all settings')->action(fn () => $this->save()),
                ])->alignEnd(),
            ]);
    }

    /**
     * @param  array<int, Component>  $components
     * @param  array<int, Action>  $headerActions
     */
    private function anchor(string $id, string $label, array $components, array $headerActions = []): Section
    {
        return Section::make($label)
            ->extraAttributes(['id' => 'settings-'.$id, 'x-ref' => 'section_'.$id])
            ->headerActions($headerActions)
            ->schema($components);
    }

    /**
     * @return array<int, Component>
     */
    private function urlComponents(): array
    {
        $cdn = TextInput::make('cdn_url')->label('CDN URL')->url()->maxLength(255)->placeholder('https://cdn.example.com')->helperText('When set, public media URLs are served from this origin. Leave blank to serve from storage.');

        if ($this->magnaPagesInstalled()) {
            return [
                TextInput::make('frontend_url')->label('Frontend URL')->url()->maxLength(255)->placeholder('https://example.com')->helperText('Base URL of your public site.'),
                TextInput::make('preview_base_url')->label('Preview base URL')->url()->maxLength(255)->placeholder('https://preview.example.com')->helperText('Base URL for draft preview links. Falls back to the frontend URL when blank.'),
                $cdn,
            ];
        }

        return [
            Placeholder::make('magna_pages_notice')
                ->hiddenLabel()
                ->content(new HtmlString(<<<'HTML'
                    <div class="flex gap-3 rounded-xl border border-violet-500/20 bg-violet-500/5 p-4">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-violet-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18"/></svg>
                        <div class="text-sm">
                            <p class="font-medium text-gray-800 dark:text-gray-100">Frontend configuration requires Magna Pages</p>
                            <p class="mt-1 text-gray-500 dark:text-gray-400">Magna is headless by default. To publish a rendered website and set its URLs, install the <strong>Magna Pages</strong> plugin, then return here.</p>
                        </div>
                    </div>
                HTML)),
            // "Then return here" was the whole instruction, with nowhere to go
            // in between — a notice naming a plugin owes you the screen that
            // installs it.
            SchemaActions::make([
                Action::make('installMagnaPages')
                    ->label('Install Magna Pages')
                    ->icon('heroicon-o-puzzle-piece')
                    ->url(PluginsPage::getUrl()),
            ]),
            $cdn,
        ];
    }

    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();

        // The non-mail aggregates have one writer, extracted from the 129
        // lines that used to live here (the BackupPlanPersister treatment).
        app(GeneralSettingsPersister::class)->persist($data, $this->magnaPagesInstalled());

        // One writer for both settings surfaces. This tab draws its fields
        // from MailSettingsPage::fields(), and a second copy of the write-back
        // is how the Resend and Postmark tokens reached that page and never
        // reached this one.
        MailSettingsPage::persist($data)->save();

        // Fold the new values over config immediately, as the dedicated page
        // does. Without it the mailer keeps whatever was read at boot, so
        // anything sent in the rest of this request — a test send, a
        // notification raised by another setting on this very form — goes out
        // through the settings the administrator has just replaced.
        app(MailConfigurator::class)->apply();

        // Refresh secret fields so they show blank again.
        $this->form->fill(array_merge($data, ['password' => null, 's3_secret' => null, 'redis_password' => null]));

        Notification::make()->title('Settings saved.')->success()->send();
    }

    /**
     * Sends a test message to the signed-in administrator.
     *
     * To themselves rather than to an address they type: the point is to prove
     * the transport works, and a form that accepts any recipient turns the
     * panel into something that can be used to send mail to strangers.
     */
    public function sendTestEmail(): void
    {
        // One body behind both test buttons — this page's copy had drifted
        // into a near-verbatim duplicate of the mail page's.
        MailSettingsPage::sendTestToSignedInUser();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save all settings')->action(fn () => $this->save()),

            /*
             * Proving the mail settings work belongs where they are edited.
             *
             * The test send was built on MailSettingsPage, which is hidden from
             * the navigation — so on every install the only reachable Email tab
             * was this one, and it had no way to find out whether a single
             * value on it was right. An administrator configuring a relay could
             * only save and hope, then wait for somebody to report that a
             * notification never arrived.
             */
            Action::make('sendTestEmail')
                ->label('Send test email')
                ->color('gray')
                ->action(fn () => $this->sendTestEmail()),
        ];
    }
}
