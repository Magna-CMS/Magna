<?php

declare(strict_types=1);

namespace Magna\Admin\Resources;

use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Magna\Admin\Resources\BackupRun\ListBackupRuns;
use Magna\Backup\BackupRun;
use Magna\Backup\Jobs\RestoreBackupJob;
use Magna\Users\User;

class BackupResource extends Resource
{
    protected static ?string $model = BackupRun::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Backup History';

    // Not shown in the sidebar directly — reached from the Backup Manager
    // settings page (its own System nav entry, see BackupSettingsPage).
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('backup.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('M d, Y H:i')
                    ->sortable()
                    ->tooltip(fn (BackupRun $record): ?string => $record->started_at?->diffForHumans()),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === BackupRun::TYPE_MANUAL ? 'info' : 'gray'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        BackupRun::STATUS_SUCCESS => 'success',
                        BackupRun::STATUS_FAILED => 'danger',
                        BackupRun::STATUS_RUNNING => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('disk')
                    ->label('Disk')
                    ->placeholder('—'),

                TextColumn::make('size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (?int $state): string => self::formatBytes($state))
                    ->placeholder('—'),

                TextColumn::make('duration')
                    ->label('Duration')
                    ->getStateUsing(function (BackupRun $record): ?string {
                        if ($record->started_at === null || $record->finished_at === null) {
                            return null;
                        }

                        return $record->started_at->diffForHumans($record->finished_at, syntax: CarbonInterface::DIFF_ABSOLUTE);
                    })
                    ->placeholder('—'),

                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    BackupRun::STATUS_PENDING => 'Pending',
                    BackupRun::STATUS_RUNNING => 'Running',
                    BackupRun::STATUS_SUCCESS => 'Success',
                    BackupRun::STATUS_FAILED => 'Failed',
                ]),
                SelectFilter::make('type')->options([
                    BackupRun::TYPE_MANUAL => 'Manual',
                    BackupRun::TYPE_SCHEDULED => 'Scheduled',
                ]),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (BackupRun $record): string => route('magna.backup.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (BackupRun $record): bool => $record->status === BackupRun::STATUS_SUCCESS
                        && auth()->user()?->can('backup.manage')),

                Action::make('downloadSecondary')
                    ->label('Download (offsite copy)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (BackupRun $record): string => route('magna.backup.download', $record).'?copy=secondary')
                    ->openUrlInNewTab()
                    ->visible(fn (BackupRun $record): bool => $record->status === BackupRun::STATUS_SUCCESS
                        && $record->secondary_disk !== null
                        && (auth()->user()?->can('backup.manage') ?? false)),

                // Stage 8: hard-gated per docs/backup-manager-plan.md — both
                // the backup.restore permission AND the actual is_super_admin
                // role flag must hold (not just one), plus a type-to-confirm
                // field. This overwrites the live database/files, so a
                // simple confirm-dialog "yes" is not enough friction for
                // what this button does.
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (BackupRun $record): bool => $record->status === BackupRun::STATUS_SUCCESS
                        && (User::current()?->isSuperAdmin() ?? false)
                        && (auth()->user()?->can('backup.restore') ?? false))
                    ->schema([
                        Placeholder::make('warning')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<div class="text-sm text-red-600 dark:text-red-400 font-semibold">'
                                .'This overwrites the live database and files (storage/app) with this backup. '
                                .'The instance goes into maintenance mode during the restore. '
                                .'A failed database restore is <u>not</u> automatically reversed — see the Backup Manager restore guide.'
                                .'</div>',
                            )),

                        Select::make('copy')
                            ->label('Restore from')
                            ->options(fn (BackupRun $record): array => array_filter([
                                'primary' => 'Primary destination',
                                'secondary' => $record->secondary_disk !== null ? 'Offsite copy' : null,
                            ]))
                            ->default('primary')
                            ->required(),

                        TextInput::make('confirm')
                            ->label('Type RESTORE to confirm')
                            ->required()
                            ->rule('in:RESTORE')
                            ->validationMessages(['in' => 'Type RESTORE exactly, in capitals, to confirm.']),
                    ])
                    ->modalHeading('Restore from this backup?')
                    ->modalSubmitActionLabel('Restore')
                    ->requiresConfirmation()
                    ->action(function (BackupRun $record, array $data): void {
                        $userId = auth()->id();

                        RestoreBackupJob::dispatch(
                            $record->id,
                            $data['copy'] === 'secondary',
                            $userId !== null ? (string) $userId : null,
                        );

                        Notification::make()
                            ->title('Restore started…')
                            ->body('The instance will enter maintenance mode shortly. You will get a notification when it finishes.')
                            ->warning()
                            ->send();
                    }),
            ])
            ->defaultSort('started_at', 'desc');
    }

    private static function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        return match (true) {
            $bytes >= 1_073_741_824 => number_format($bytes / 1_073_741_824, 2).' GB',
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 1).' MB',
            $bytes >= 1_024 => number_format($bytes / 1_024, 0).' KB',
            default => number_format($bytes).' B',
        };
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListBackupRuns::route('/'),
        ];
    }
}
