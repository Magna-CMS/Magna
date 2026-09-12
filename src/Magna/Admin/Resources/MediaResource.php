<?php

declare(strict_types=1);

namespace Magna\Admin\Resources;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Magna\Admin\Resources\Media\CreateMedia;
use Magna\Admin\Resources\Media\EditMedia;
use Magna\Admin\Resources\Media\ListMedia;
use Magna\Admin\Resources\Media\TrashMedia;
use Magna\Media\Media;
use Magna\Media\MediaFolder;
use Magna\Media\MediaUrlResolver;
use Magna\Users\User;

class MediaResource extends \Filament\Resources\Resource
{
    protected static ?string $model = Media::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Media library';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'original_filename';

    /** @return string[] */
    public static function getGloballySearchableAttributes(): array
    {
        return ['original_filename', 'title', 'alt'];
    }

    // ── Authorization: reuse the same media.* permissions already enforced
    //    by the JSON Management API's MediaController (S1-01) ────────────────

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('media.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('media.upload') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('media.upload') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('media.delete') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->can('media.delete') ?? false;
    }

    public static function canRestore(Model $record): bool
    {
        return auth()->user()?->can('media.upload') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('file')
                ->label('Upload file')
                ->required()
                ->disk('public')
                ->directory('media')
                ->maxSize(102_400)
                // Defense in depth only — the authoritative check is the
                // content-sniffed allowlist in MediaIngestor::ingest(), which
                // every upload is routed through by CreateMedia::handleRecordCreation().
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'application/pdf'])
                ->hiddenOn('edit'),

            TextInput::make('alt')
                ->label('Alt text')
                ->maxLength(255)
                ->helperText('Describe the image for screen readers and search engines.'),

            TextInput::make('title')
                ->label('Title')
                ->maxLength(255),

            Select::make('folder_id')
                ->label('Folder')
                ->options(
                    fn (): array => MediaFolder::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all(),
                )
                ->searchable()
                ->nullable()
                ->placeholder('No folder'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Respect the category filter set by the MediaStatsWidget cards
            // (shared with the grid view via the ListMedia page).
            ->modifyQueryUsing(function (Builder $query, $livewire): void {
                $query->with(['folder', 'uploader']);

                if (method_exists($livewire, 'applyCategory')) {
                    $livewire->applyCategory($query);
                }
            })
            ->columns([
                TextColumn::make('original_filename')
                    ->label('Name')
                    // Show the title given at upload; fall back to the file name.
                    ->state(fn (Media $record): string => filled($record->title) ? $record->title : $record->original_filename)
                    ->searchable(['title', 'original_filename'])
                    ->sortable()
                    ->limit(28)
                    ->tooltip(fn (Media $record): string => filled($record->title) ? $record->title : $record->original_filename),

                TextColumn::make('mime_type')
                    ->label('Type')
                    ->sortable()
                    ->badge()
                    ->color('info'),

                TextColumn::make('size')
                    ->label('Size')
                    ->sortable()
                    ->formatStateUsing(static function (int $state): string {
                        if ($state >= 1_048_576) {
                            return number_format($state / 1_048_576, 2).' MB';
                        }

                        return number_format($state / 1_024, 1).' KB';
                    }),

                TextColumn::make('folder.name')
                    ->label('Folder')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('uploader.name')
                    ->label('Uploaded by')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),

                SelectFilter::make('uploaded_by')
                    ->label('Uploaded by')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),

                Filter::make('uploaded_by_others')
                    ->label('Uploaded by other users')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('uploaded_by')
                        ->where('uploaded_by', '!=', (string) auth()->id())),
            ])
            ->actions([
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading(fn (Media $record): string => $record->original_filename)
                    ->modalContent(fn (Media $record): HtmlString => self::previewHtml($record))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Action::make('copyUrl')
                    ->label('Copy URL')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->action(function (Media $record): void {
                        $url = Storage::disk($record->disk)->url($record->path);

                        Notification::make()
                            ->title('Public URL copied')
                            ->body($url)
                            ->info()
                            ->persistent()
                            ->send();
                    }),

                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** @return array<string, class-string> */
    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
            'create' => CreateMedia::route('/create'),
            'edit' => EditMedia::route('/{record}/edit'),
            'trash' => TrashMedia::route('/trash'),
        ];
    }

    private static function previewHtml(Media $record): HtmlString
    {
        // 'hero' is the largest generated derivative (1920x1080, scaled down
        // not cropped) — enough for this modal without pulling the full-size
        // original over the wire. Falls back to the original automatically
        // (via MediaUrlResolver) if no conversion exists yet.
        $url = e(app(MediaUrlResolver::class)->publicUrl($record, 'hero'));
        // Through the resolver, never the raw disk URL: for an SVG the
        // resolver hands back the serve-controller route, whose forced
        // Content-Disposition: attachment is the contract that keeps SVG
        // markup from ever rendering inline on this origin — a raw
        // Storage::url() link was the one place that bypassed it.
        $originalUrl = e(app(MediaUrlResolver::class)->publicUrl($record));
        $mime = $record->mime_type;

        if (str_starts_with($mime, 'image/')) {
            $alt = e($record->alt ?? '');
            $html = <<<HTML
                <div class="flex flex-col items-center gap-3 p-2">
                    <img src="{$url}" alt="{$alt}" class="max-w-full max-h-96 rounded-lg object-contain" />
                    <a href="{$originalUrl}" target="_blank" rel="noopener noreferrer"
                       class="text-sm text-primary-500 hover:underline">
                        Open full size ↗
                    </a>
                </div>
            HTML;
        } elseif (str_starts_with($mime, 'video/')) {
            $html = <<<HTML
                <div class="flex flex-col items-center gap-3 p-2">
                    <video src="{$url}" controls class="max-w-full max-h-96 rounded-lg"></video>
                </div>
            HTML;
        } elseif (str_starts_with($mime, 'audio/')) {
            $html = <<<HTML
                <div class="flex flex-col items-center gap-3 p-4">
                    <audio src="{$url}" controls class="w-full"></audio>
                </div>
            HTML;
        } elseif ($mime === 'application/pdf') {
            $html = <<<HTML
                <div class="p-2">
                    <iframe src="{$url}" class="w-full rounded-lg border border-gray-200 dark:border-gray-700"
                            style="height: 480px;"></iframe>
                </div>
            HTML;
        } else {
            $filename = e($record->original_filename);
            $mime = e($mime);
            $html = <<<HTML
                <div class="flex flex-col items-center gap-4 p-6 text-center">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{$filename}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{$mime}</p>
                    <a href="{$originalUrl}" target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg
                              bg-primary-600 text-white hover:bg-primary-700 transition-colors">
                        Download file
                    </a>
                </div>
            HTML;
        }

        return new HtmlString($html);
    }
}
