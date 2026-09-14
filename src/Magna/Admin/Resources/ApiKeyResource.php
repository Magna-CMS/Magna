<?php

declare(strict_types=1);

namespace Magna\Admin\Resources;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\PageRegistration;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Magna\Admin\Resources\ApiKey\ManageApiKeys;
use Magna\Auth\ApiKey;

class ApiKeyResource extends \Filament\Resources\Resource
{
    protected static ?string $model = ApiKey::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'API';

    protected static ?string $navigationLabel = 'API Keys';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('tokens.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false; // Creation is handled by the custom header action on ManageApiKeys.
    }

    public static function canEdit(Model $record): bool
    {
        return false; // Secrets are immutable once generated.
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('tokens.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('key')
                    ->label('Key')
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (string $state): string => substr($state, 0, 24).'...')
                    ->tooltip(fn (string $state): string => 'Click to copy: '.$state)
                    ->copyable(),

                TextColumn::make('secret_hidden')
                    ->label('Secret')
                    ->getStateUsing(fn (): string => '••••••••••••••••')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->tooltip('Shown only once at creation — cannot be retrieved.'),

                TextColumn::make('scope')
                    ->label('Scope')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'management' => 'warning',
                        default => 'info',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date('M d, Y')
                    ->placeholder('Never')
                    ->sortable()
                    ->color(fn (ApiKey $record): string => $record->expires_at && $record->expires_at->isPast() ? 'danger' : 'gray'
                    ),

                TextColumn::make('createdBy.name')
                    ->label('Created by')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Action::make('toggle')
                    ->label(fn (ApiKey $record): string => $record->is_active ? 'Disable' : 'Enable')
                    ->icon(fn (ApiKey $record): string => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (ApiKey $record): string => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (ApiKey $record): bool => $record->update(['is_active' => ! $record->is_active]))
                    ->visible(fn (): bool => auth()->user()?->can('tokens.manage') ?? false),

                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('tokens.manage') ?? false),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-key')
            ->emptyStateHeading('No API keys yet')
            ->emptyStateDescription('Generate your first key to connect external apps to Magna.');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ManageApiKeys::route('/'),
        ];
    }
}
