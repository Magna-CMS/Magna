<?php

declare(strict_types=1);

namespace Magna\Admin\Resources;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Magna\Admin\Resources\AuditLog\ListAuditLogs;
use Magna\Admin\Support\ActionLabel;
use Magna\Audit\AuditLog;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'action';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('audit.view') ?? false;
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('actorUser'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M d, Y H:i')
                    ->sortable()
                    ->searchable()
                    ->tooltip(fn (AuditLog $record): string => $record->created_at->diffForHumans()),

                TextColumn::make('action')
                    ->label('Action')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => ActionLabel::get($state))
                    ->tooltip(fn (string $state): string => $state),

                TextColumn::make('actor_name')
                    ->label('User')
                    ->getStateUsing(fn (AuditLog $record): string => $record->actorUser->name ?? 'System')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('actorUser', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    )
                    ->icon('heroicon-m-user-circle')
                    ->iconColor('gray'),

                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->searchable()
                    ->formatStateUsing(
                        fn (?string $state): string => $state !== null
                            ? class_basename($state)
                            : '—',
                    ),

                TextColumn::make('ip')
                    ->label('IP Address')
                    ->searchable()
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('created_at')
                    ->label('Date')
                    ->date()
                    ->collapsible(),
            ])
            ->defaultGroup('created_at')
            ->filters([
                SelectFilter::make('action')
                    ->label('Action')
                    ->options(function (): array {
                        $options = [];

                        foreach (AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action') as $action) {
                            if (is_string($action)) {
                                $options[$action] = ActionLabel::get($action);
                            }
                        }

                        return $options;
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordAction(null);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }
}
