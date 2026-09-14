<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\FileUpload;
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
use Magna\Admin\Resources\BackupResource;
use Magna\Admin\Support\S3CredentialFields;
use Magna\Backup\BackupPlanPersister;
use Magna\Backup\BackupRun;
use Magna\Backup\Jobs\RestoreBackupJob;
use Magna\Backup\Jobs\RunBackupJob;
use Magna\Settings\BackupSettings;

/**
 * Backup Manager settings. Unlike the other *SettingsPage classes, this one
 * keeps its own sidebar entry rather than folding into the unified
 * SettingsPage hub — see docs/backup-manager-plan.md, Decision #4.
 *
 * @property ComponentContainer $form
 */
class BackupSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Backup Manager';

    protected static ?string $title = 'Backup Manager';

    protected static ?int $navigationSort = 100;

    protected string $view = 'magna::admin.backup-settings';

    public ?array $data = [];

    /** True while a manually-triggered run is in progress, so the page polls RunBackupJob::progress(). */
    public bool $running = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('backup.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = BackupSettings::get();

        $this->form->fill([
            'enabled' => $settings->enabled,
            'disk' => $settings->disk,
            's3_key' => $settings->s3_key,
            // Secrets are never pre-filled — show placeholder instead.
            's3_secret' => null,
            's3_bucket' => $settings->s3_bucket,
            's3_region' => $settings->s3_region,
            's3_url' => $settings->s3_url,
            'secondary_disk' => $settings->secondary_disk,
            'secondary_s3_key' => $settings->secondary_s3_key,
            'secondary_s3_secret' => null,
            'secondary_s3_bucket' => $settings->secondary_s3_bucket,
            'secondary_s3_region' => $settings->secondary_s3_region,
            'secondary_s3_url' => $settings->secondary_s3_url,
            'encryption_password' => null,
            'size_warning_mb' => $settings->size_warning_mb,
            'frequency' => $settings->frequency,
            'cron_expression' => $settings->cron_expression,
            'run_at' => $settings->run_at,
            'retention_count' => $settings->retention_count,
            'retention_days' => $settings->retention_days,
            'include_database' => $settings->include_database,
            'include_files' => $settings->include_files,
            'include_config' => $settings->include_config,
            'excluded_tables' => $settings->excluded_tables,
            'notify_emails' => $settings->notify_emails,
        ]);
    }

    /** The storage drivers both destination selects offer. */
    private const DRIVER_OPTIONS = [
        'server' => 'This server (private folder)',
        'local' => 'Local filesystem (media disk)',
        'public' => 'Public (local, web-accessible)',
        's3' => 'Amazon S3',
        's3-like' => 'S3-compatible (R2, MinIO, etc.)',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                $this->destinationSection(),
                $this->encryptionSection(),
                $this->secondaryDestinationSection(),
                $this->scheduleSection(),
                $this->contentSection(),
                $this->notificationsSection(),

                SchemaActions::make([
                    Action::make('saveBottom')
                        ->label('Save settings')
                        ->action(fn () => $this->save()),
                ])->alignEnd(),
            ]);
    }

    private function destinationSection(): Section
    {
        return Section::make('Destination')
            ->description('Where backups are written. Must be a different disk/bucket than the Storage settings media disk — pointing both at the same place defeats the purpose of a backup.')
            ->schema([
                Toggle::make('enabled')
                    ->label('Automated backups enabled')
                    ->inline(false),

                Select::make('disk')
                    ->label('Storage driver')
                    ->options(self::DRIVER_OPTIONS)
                    ->required()
                    ->live(),

                ...S3CredentialFields::make(fn (callable $get): bool => in_array($get('disk'), ['s3', 's3-like'], true)),
            ]);
    }

    private function encryptionSection(): Section
    {
        return Section::make('Encryption')
            ->description('Required whenever the primary or secondary destination is S3/S3-compatible — the archive leaves the server, so it must be encrypted before it does. Not required for local/public, where it never leaves the filesystem.')
            ->schema([
                TextInput::make('encryption_password')
                    ->label('Encryption password')
                    ->password()
                    ->nullable()
                    ->placeholder('[secret — leave blank to keep current]')
                    ->helperText('Archive-level AES-256 (ZipArchive native encryption). Leave blank to keep the existing password unchanged. Store this somewhere separate from the backups themselves — losing it means the backups are unrecoverable.'),
            ]);
    }

    private function secondaryDestinationSection(): Section
    {
        return Section::make('Secondary destination (offsite copy)')
            ->description('Optional. Completes the 3-2-1 backup rule: a second copy, ideally on a different provider/region than the primary above.')
            ->schema([
                Select::make('secondary_disk')
                    ->label('Storage driver')
                    ->options(self::DRIVER_OPTIONS)
                    ->nullable()
                    ->placeholder('Not configured — single destination only')
                    ->helperText('Choosing the same provider/region as the primary works, but defeats part of the point of a second copy.')
                    ->live(),

                ...S3CredentialFields::make(
                    fn (callable $get): bool => in_array($get('secondary_disk'), ['s3', 's3-like'], true),
                    prefix: 'secondary_',
                ),
            ]);
    }

    private function scheduleSection(): Section
    {
        return Section::make('Schedule & retention')
            ->schema([
                Select::make('frequency')
                    ->label('Frequency')
                    ->options([
                        'daily' => 'Daily',
                        'weekly' => 'Weekly',
                        'custom_cron' => 'Custom cron expression',
                    ])
                    ->required()
                    ->live(),

                TextInput::make('cron_expression')
                    ->label('Cron expression')
                    ->maxLength(100)
                    ->nullable()
                    ->placeholder('0 2 * * *')
                    ->helperText('Standard 5-field cron syntax. Validated on save — an invalid expression is rejected rather than silently never firing.')
                    ->visible(fn (callable $get): bool => $get('frequency') === 'custom_cron'),

                TextInput::make('run_at')
                    ->label('Run at (HH:MM)')
                    ->maxLength(5)
                    ->required()
                    ->placeholder('02:00')
                    ->visible(fn (callable $get): bool => $get('frequency') !== 'custom_cron'),

                TextInput::make('retention_count')
                    ->label('Keep at least this many recent backups')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->maxValue(1000),

                TextInput::make('retention_days')
                    ->label('Keep backups for this many days')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->maxValue(3650),
            ]);
    }

    private function contentSection(): Section
    {
        return Section::make('What to back up')
            ->schema([
                Toggle::make('include_database')->label('Database')->inline(false)->live(),
                Toggle::make('include_files')->label('Media & files')->inline(false),
                Toggle::make('include_config')->label('App settings (config export)')->inline(false),

                TagsInput::make('excluded_tables')
                    ->label('Exclude these tables from the database dump')
                    ->placeholder('Add a table name')
                    ->helperText('E.g. a huge analytics/log table that does not need to ride along in every backup.')
                    ->visible(fn (callable $get): bool => (bool) $get('include_database')),
            ]);
    }

    private function notificationsSection(): Section
    {
        return Section::make('Notifications')
            ->schema([
                TagsInput::make('notify_emails')
                    ->label('Notify these emails')
                    ->placeholder('Add an email address')
                    ->nestedRecursiveRules(['email'])
                    ->helperText('Failure alerts always send — to this list if set, otherwise to every super admin. Success and size-warning alerts are optional and only send if this list is non-empty.'),

                TextInput::make('size_warning_mb')
                    ->label('Size warning threshold (MB)')
                    ->numeric()
                    ->nullable()
                    ->minValue(1)
                    ->helperText('Alert (not block) when a backup exceeds this size — an early signal of runaway growth. Leave blank to disable.'),
            ]);
    }

    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();

        $result = app(BackupPlanPersister::class)->persist($data);

        if (! $result['ok']) {
            Notification::make()
                ->title($result['title'])
                ->body((string) $result['message'])
                ->danger()
                ->send();

            return;
        }

        // Refresh secret fields so they show blank again, same convention as
        // SettingsPage::save().
        $this->form->fill(array_merge($data, [
            's3_secret' => null,
            'secondary_s3_secret' => null,
            'encryption_password' => null,
        ]));

        Notification::make()
            ->title($result['title'])
            ->success()
            ->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewHistory')
                ->label('View backup history')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn (): string => BackupResource::getUrl())
                ->visible(fn (): bool => auth()->user()?->can('backup.view') ?? false),

            Action::make('runNow')
                ->label('Run backup now')
                ->icon('heroicon-o-play')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Runs a manual backup using the current saved settings below. This works regardless of the "Automated backups enabled" toggle, which only controls the schedule (Stage 5).')
                ->action(function (): void {
                    $userId = auth()->id();

                    RunBackupJob::dispatch(BackupRun::TYPE_MANUAL, $userId !== null ? (string) $userId : null);
                    $this->running = true;

                    Notification::make()->title('Backup started…')->send();
                }),

            // Hard-gated the same way Restore is on BackupResource (Stage 8):
            // an uploaded archive is at least as dangerous as restoring one
            // of this site's own recorded runs, since it can come from
            // anywhere — both the is_super_admin role flag and the
            // backup.restore permission must hold, not either alone.
            Action::make('importBackup')
                ->label('Import backup')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('danger')
                ->visible(fn (): bool => (auth()->user()?->isSuperAdmin() ?? false)
                    && (auth()->user()?->can('backup.restore') ?? false))
                ->schema([
                    Placeholder::make('warning')
                        ->hiddenLabel()
                        ->content(new HtmlString(
                            '<div class="text-sm text-red-600 dark:text-red-400 font-semibold">'
                            .'Restores the live database and files (storage/app) from an uploaded archive — not one of this Magna instance\'s own recorded backups. '
                            .'The instance goes into maintenance mode during the restore. '
                            .'A failed database restore is <u>not</u> automatically reversed — see the Backup Manager restore guide.'
                            .'</div>',
                        )),

                    FileUpload::make('archive')
                        ->label('Backup archive (.zip)')
                        ->disk('local')
                        ->directory('magna-backup-imports')
                        ->visibility('private')
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                        ->maxSize(5_242_880) // 5 GB, in KB — generous ceiling for a database + media archive
                        ->required(),

                    TextInput::make('archive_password')
                        ->label('Archive password (if encrypted)')
                        ->password()
                        ->nullable()
                        ->helperText('Leave blank to use this Magna instance\'s own configured encryption password (Encryption section above), if any.'),

                    TextInput::make('confirm')
                        ->label('Type RESTORE to confirm')
                        ->required()
                        ->rule('in:RESTORE')
                        ->validationMessages(['in' => 'Type RESTORE exactly, in capitals, to confirm.']),
                ])
                ->modalHeading('Import and restore from an uploaded backup?')
                ->modalSubmitActionLabel('Import & restore')
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $userId = auth()->id();

                    // The password never becomes a plain job-queue property —
                    // see RestoreBackupJob::stashImportPassword()'s docblock.
                    $passwordToken = filled($data['archive_password'] ?? null)
                        ? RestoreBackupJob::stashImportPassword((string) $data['archive_password'])
                        : null;

                    RestoreBackupJob::dispatch(
                        backupRunId: null,
                        useSecondary: false,
                        triggeredBy: $userId !== null ? (string) $userId : null,
                        importDisk: 'local',
                        importPath: (string) $data['archive'],
                        importPasswordToken: $passwordToken,
                    );

                    Notification::make()
                        ->title('Import started…')
                        ->body('The instance will enter maintenance mode shortly. You will get a notification when it finishes.')
                        ->warning()
                        ->send();
                }),
        ];
    }

    /** Poll a running manual backup; notify when it finishes. */
    public function pollBackupRun(): void
    {
        if (! $this->running) {
            return;
        }

        $progress = RunBackupJob::progress();

        if ($progress['state'] === 'completed') {
            $this->running = false;
            Notification::make()->title('Backup complete')->body($progress['message'])->success()->send();
        } elseif ($progress['state'] === 'failed') {
            $this->running = false;
            Notification::make()->title("Backup didn't complete")->body($progress['message'])->danger()->send();
        } elseif ($progress['state'] === 'rejected') {
            $this->running = false;
            Notification::make()->title('Backup skipped')->body($progress['message'])->warning()->send();
        }
    }
}
