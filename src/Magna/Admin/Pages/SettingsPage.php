<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\ComponentContainer;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Magna\Admin\Support\S3CredentialFields;
use Magna\Plugins\PluginRecord;
use Magna\Settings\ContentSettings;
use Magna\Settings\GeneralSettings;
use Magna\Settings\LocalizationSettings;
use Magna\Settings\MailSettings;
use Magna\Settings\MediaSettings;
use Magna\Settings\PerformanceSettings;
use Magna\Settings\SecuritySettings;
use Magna\Settings\StorageSettings;
use Magna\Settings\UrlSettings;

/**
 * Unified settings page: every settings group lives on one scrollable page,
 * split into anchored sections with a sticky side sub-nav (see the view). This
 * replaces the previous one-page-per-group navigation.
 *
 * @property ComponentContainer $form
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

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    /**
     * The section anchors, in order, for the sticky side navigation.
     *
     * @return array<int, array{id: string, label: string, icon: string}>
     */
    public function sections(): array
    {
        return [
            ['id' => 'general', 'label' => 'General', 'icon' => 'heroicon-o-cog-6-tooth'],
            ['id' => 'localization', 'label' => 'Localization', 'icon' => 'heroicon-o-language'],
            ['id' => 'content', 'label' => 'Content', 'icon' => 'heroicon-o-document-text'],
            ['id' => 'media', 'label' => 'Media', 'icon' => 'heroicon-o-photo'],
            ['id' => 'email', 'label' => 'Email', 'icon' => 'heroicon-o-envelope'],
            ['id' => 'storage', 'label' => 'Storage', 'icon' => 'heroicon-o-circle-stack'],
            ['id' => 'urls', 'label' => 'URLs & Frontend', 'icon' => 'heroicon-o-link'],
            ['id' => 'security', 'label' => 'Security', 'icon' => 'heroicon-o-shield-check'],
            ['id' => 'performance', 'label' => 'Performance', 'icon' => 'heroicon-o-bolt'],
        ];
    }

    private function magnaPagesInstalled(): bool
    {
        return PluginRecord::query()
            ->where('name', 'magna/pages')
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
     * @param  array<int, mixed>  $components
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
     * @return array<int, mixed>
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
            $cdn,
        ];
    }

    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();

        $str = static fn (mixed $v): string => is_string($v) ? $v : '';
        $trim = static fn (mixed $v): string => rtrim(is_string($v) ? $v : '', '/');
        $int = static fn (mixed $v): int => (int) $v;

        $general = GeneralSettings::get();
        $general->registration_enabled = (bool) ($data['registration_enabled'] ?? false);
        $general->timezone = $str($data['timezone'] ?? 'UTC');
        $general->default_locale = $str($data['default_locale'] ?? 'en');
        $general->date_format = $str($data['date_format'] ?? 'Y-m-d');
        $general->time_format = $str($data['time_format'] ?? 'H:i');
        $general->first_day_of_week = $int($data['first_day_of_week'] ?? 1);
        $general->currency = $str($data['currency'] ?? '');
        $general->save();

        $localization = LocalizationSettings::get();
        $localization->available_locales = array_values((array) ($data['available_locales'] ?? []));
        $localization->fallback_locale = $str($data['fallback_locale'] ?? 'en');
        $localization->rtl_locales = array_values((array) ($data['rtl_locales'] ?? []));
        $localization->save();

        $content = ContentSettings::get();
        $content->default_status = $str($data['default_status'] ?? 'draft');
        $content->revision_limit = $int($data['revision_limit'] ?? 50);
        $content->autosave_interval = $int($data['autosave_interval'] ?? 60);
        $content->save();

        $media = MediaSettings::get();
        $media->max_image_upload_bytes = $int($data['max_image_upload_bytes'] ?? 0);
        $media->max_svg_upload_bytes = $int($data['max_svg_upload_bytes'] ?? 0);
        $media->max_document_upload_bytes = $int($data['max_document_upload_bytes'] ?? 0);
        $media->default_image_quality = $int($data['default_image_quality'] ?? 90);
        $media->webp_enabled = (bool) ($data['webp_enabled'] ?? false);
        $media->avif_enabled = (bool) ($data['avif_enabled'] ?? false);
        $media->save();

        $mail = MailSettings::get();
        $mail->driver = $str($data['driver'] ?? 'smtp');
        $mail->host = $str($data['host'] ?? '');
        $mail->port = $int($data['port'] ?? 25);
        $mail->username = ($data['username'] ?? null) ?: null;
        $mail->from_address = $str($data['from_address'] ?? '');
        $mail->from_name = $str($data['from_name'] ?? '');
        if (filled($data['password'] ?? null)) {
            $mail->password = $str($data['password']);
        }
        $mail->save();

        $storage = StorageSettings::get();
        $storage->disk = $str($data['disk'] ?? 'local');
        $storage->s3_key = ($data['s3_key'] ?? null) ?: null;
        $storage->s3_bucket = ($data['s3_bucket'] ?? null) ?: null;
        $storage->s3_region = ($data['s3_region'] ?? null) ?: null;
        $storage->s3_url = ($data['s3_url'] ?? null) ?: null;
        if (filled($data['s3_secret'] ?? null)) {
            $storage->s3_secret = $str($data['s3_secret']);
        }
        $storage->save();

        $url = UrlSettings::get();
        $url->cdn_url = $trim($data['cdn_url'] ?? null);
        if ($this->magnaPagesInstalled()) {
            $url->frontend_url = $trim($data['frontend_url'] ?? null);
            $url->preview_base_url = $trim($data['preview_base_url'] ?? null);
        }
        $url->save();

        $security = SecuritySettings::get();
        $security->force_https = (bool) ($data['force_https'] ?? false);
        $security->require_email_verification = (bool) ($data['require_email_verification'] ?? false);
        $security->session_lifetime = $int($data['session_lifetime'] ?? 120);
        $security->save();

        $performance = PerformanceSettings::get();
        $performance->cache_driver = $str($data['cache_driver'] ?? 'database');
        $performance->queue_connection = $str($data['queue_connection'] ?? 'database');
        // Only written when actually present: these inputs are ->visible()
        // behind a Redis driver being selected, and Filament omits hidden
        // components from the state. Falling back to a default here would
        // reset a configured Redis host to 127.0.0.1 every time someone saved
        // this page while on the file/database drivers.
        if (array_key_exists('redis_host', $data)) {
            $performance->redis_host = $str($data['redis_host']) ?: '127.0.0.1';
        }
        if (array_key_exists('redis_port', $data)) {
            $performance->redis_port = $int($data['redis_port']) ?: 6379;
        }
        if (array_key_exists('redis_database', $data)) {
            $performance->redis_database = $int($data['redis_database']);
        }
        $performance->octane_server = $str($data['octane_server'] ?? 'frankenphp');
        if (filled($data['redis_password'] ?? null)) {
            $performance->redis_password = $str($data['redis_password']);
        }
        $performance->save();

        // Refresh secret fields so they show blank again.
        $this->form->fill(array_merge($data, ['password' => null, 's3_secret' => null, 'redis_password' => null]));

        Notification::make()->title('Settings saved.')->success()->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save all settings')->action(fn () => $this->save()),
        ];
    }
}
