<?php

declare(strict_types=1);

namespace Magna\Admin\Widgets;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Magna\Admin\Resources\AuditLogResource;
use Magna\Admin\Support\ActionLabel;
use Magna\Audit\AuditLog;

class RecentActivity extends TableWidget
{
    protected static ?int $sort = 2;

    // Defer the audit-log query so the dashboard shell paints first.
    protected static bool $isLazy = true;

    public $tableRecordsPerPage = 10;

    protected static ?string $heading = 'Recent Activity';

    // Stage 10 (A-2): this widget rendered the audit log to any Dashboard
    // viewer regardless of AuditLogResource::canViewAny()'s audit.view gate
    // (S1-16) — defeating that fix for anyone who just lands on the
    // Dashboard instead of opening the Audit Log resource directly.
    public static function canView(): bool
    {
        return AuditLogResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn (): Builder => AuditLog::query()
                    ->with('actorUser')
                    ->latest('created_at')
                    ->limit(10),
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->sortable()
                    ->tooltip(fn (AuditLog $record): string => $record->created_at->format('M d, Y H:i')),

                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => ActionLabel::get($state)),

                TextColumn::make('actor')
                    ->label('User')
                    ->getStateUsing(fn (AuditLog $record): string => $record->actorUser->name ?? 'System')
                    ->icon('heroicon-m-user-circle')
                    ->iconColor('gray'),
            ])
            ->headerActions([
                Action::make('view_all')
                    ->label('View all')
                    ->url(AuditLogResource::getUrl())
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->size('sm')
                    ->color('gray'),
            ])
            ->paginated(false)
            ->defaultSort('created_at', 'desc');
    }
}
