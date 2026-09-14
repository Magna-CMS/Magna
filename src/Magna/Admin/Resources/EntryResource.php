<?php

declare(strict_types=1);

namespace Magna\Admin\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Magna\Admin\Resources\Entry\CreateEntry;
use Magna\Admin\Resources\Entry\EditEntry;
use Magna\Admin\Resources\Entry\ListEntries;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\EntryStatus;
use Magna\Content\SchemaRegistry;
use Magna\Contracts\ExtendsEntryForm;
use Magna\Settings\GeneralSettings;
use Magna\Settings\LocalizationSettings;

class EntryResource extends Resource
{
    protected static ?string $model = Entry::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Content';

    protected static ?int $navigationSort = 10;

    // ── Authorization: content.{type}.{action}, mirroring the Management API ─

    public static function canViewAny(): bool
    {
        return self::hasContentPermission('view');
    }

    public static function canCreate(): bool
    {
        return self::hasContentPermission('create');
    }

    public static function canEdit(Model $record): bool
    {
        /** @var Entry $record */
        return self::hasContentPermission('update', $record->getHandle());
    }

    public static function canDelete(Model $record): bool
    {
        /** @var Entry $record */
        return self::hasContentPermission('delete', $record->getHandle());
    }

    public static function canDeleteAny(): bool
    {
        return self::hasContentPermission('delete');
    }

    private static function hasContentPermission(string $action, ?string $handle = null): bool
    {
        $handle ??= static::getTypeHandleFromRequest();
        if ($handle === '') {
            return false;
        }

        return auth()->user()?->can("content.{$handle}.{$action}") ?? false;
    }

    // ── Dynamic navigation: one item per registered content type ─────────────

    public static function getNavigationItems(): array
    {
        /** @var SchemaRegistry $registry */
        $registry = app(SchemaRegistry::class);

        $items = [];
        foreach ($registry->all() as $handle => $type) {
            $items[] = NavigationItem::make($type->displayName)
                ->group('Content')
                ->icon('heroicon-o-document-text')
                ->url(static::getUrl('index', ['type' => $handle]))
                ->isActiveWhen(
                    fn (): bool => request()->query('type') === $handle,
                );
        }

        return $items;
    }

    // ── Form: dynamically built from the content type's field schema ─────────

    public static function form(Schema $schema): Schema
    {
        $type = self::resolveCurrentType();
        if ($type === null) {
            return $schema->components([]);
        }

        $fieldComponents = self::buildFieldComponents($type);

        return $schema->components([
            Section::make('Fields')
                ->columns(2)
                ->schema($fieldComponents),
        ]);
    }

    /** @return list<Component> */
    private static function buildFieldComponents(ContentType $type): array
    {
        $components = [];
        foreach ($type->fields as $field) {
            $components[] = $field->type->toFilamentComponent($field);
        }

        // Merge Filament components contributed by enabled plugins implementing
        // ExtendsEntryForm. The contract promises components but cannot type
        // them; anything else would break the whole entry form, so it is
        // dropped rather than trusted.
        if (app()->bound('magna.entry_form_plugins')) {
            /** @var list<ExtendsEntryForm> $entryFormPlugins */
            $entryFormPlugins = app()->make('magna.entry_form_plugins');
            foreach ($entryFormPlugins as $plugin) {
                foreach ($plugin->entryFormExtensions($type->handle) as $component) {
                    if ($component instanceof Component) {
                        $components[] = $component;
                    }
                }
            }
        }

        return $components;
    }

    // ── Table: status/locale/updated columns + type-scoped query ────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->limit(10)
                    ->fontFamily('mono')
                    ->searchable()
                    ->copyable(),

                BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => EntryStatus::Published->value,
                        'warning' => EntryStatus::Draft->value,
                        'info' => EntryStatus::Scheduled->value,
                        'gray' => EntryStatus::Archived->value,
                    ]),

                TextColumn::make('locale')
                    ->label('Locale')
                    ->badge()
                    ->color('gray')
                    ->default('—'),

                TextColumn::make('author_id')
                    ->label('Author')
                    ->limit(10)
                    ->fontFamily('mono')
                    ->default('—'),

                TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime('d M Y H:i')
                    ->default('—')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(EntryStatus::cases())
                        ->mapWithKeys(fn (EntryStatus $s): array => [$s->value => ucfirst($s->value)])
                        ->all()),

                SelectFilter::make('locale')
                    ->label('Locale')
                    ->options(function (): array {
                        $locSettings = LocalizationSettings::get();
                        $default = GeneralSettings::get()->default_locale;
                        $locales = array_unique(array_merge([$default], $locSettings->available_locales, ['']));

                        return array_combine($locales, array_map(
                            fn (string $l): string => $l === '' ? '(default)' : $l,
                            $locales,
                        ));
                    })
                    ->visible(fn (): bool => self::resolveCurrentType()->localizable ?? false),
            ])
            ->actions([
                // Publish action
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-m-arrow-up-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Entry $record): void {
                        $handle = static::getTypeHandleFromRequest();
                        if ($handle !== '') {
                            app(EntryManager::class)->publish($record);
                        }
                    })
                    ->visible(fn (Entry $record): bool => $record->status !== EntryStatus::Published
                        && self::hasContentPermission('publish', $record->getHandle())),

                // Unpublish action
                Action::make('unpublish')
                    ->label('Unpublish')
                    ->icon('heroicon-m-arrow-down-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Entry $record): void {
                        app(EntryManager::class)->unpublish($record);
                    })
                    ->visible(fn (Entry $record): bool => $record->status === EntryStatus::Published
                        && self::hasContentPermission('publish', $record->getHandle())),

                // Create translation action (localizable types only)
                Action::make('create_translation')
                    ->label('Translate')
                    ->icon('heroicon-m-language')
                    ->color('info')
                    ->form([
                        Select::make('target_locale')
                            ->label('Target locale')
                            ->required()
                            ->options(function (): array {
                                $locSettings = LocalizationSettings::get();
                                $default = GeneralSettings::get()->default_locale;
                                $locales = array_unique(array_merge([$default], $locSettings->available_locales));

                                return array_combine($locales, $locales);
                            })
                            ->helperText('A copy of this entry will be created in the selected locale (starting as Draft).'),
                    ])
                    ->action(function (Entry $record, array $data): void {
                        $locale = is_string($data['target_locale']) ? $data['target_locale'] : '';
                        if ($locale !== '') {
                            $actorId = auth()->id();
                            app(EntryManager::class)->createTranslation(
                                $record,
                                $locale,
                                $actorId !== null ? (string) $actorId : null,
                            );
                        }
                    })
                    ->visible(fn (Entry $record): bool => (self::resolveCurrentType()->localizable ?? false)
                        && self::hasContentPermission('create', $record->getHandle()))
                    ->successNotificationTitle('Translation created as draft.'),

                // View revisions action
                Action::make('revisions')
                    ->label('Revisions')
                    ->icon('heroicon-m-clock')
                    ->color('gray')
                    ->modalHeading('Revision History')
                    ->modalContent(fn (Entry $record): View => view()->make(
                        'magna::admin.entry-revisions',
                        ['entry' => $record, 'type' => self::getTypeHandleFromRequest()]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->visible(fn (Entry $record): bool => self::hasContentPermission('view', $record->getHandle())),

                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    // ── Query scoped to the current content type ─────────────────────────────

    /** @return Builder<Entry> */
    public static function getEloquentQuery(): Builder
    {
        $handle = static::getTypeHandleFromRequest();
        if ($handle === '') {
            // No type selected — return empty builder from base Entry table.
            return Entry::query()->whereRaw('1 = 0');
        }

        return Entry::type($handle);
    }

    // ── Global search ────────────────────────────────────────────────────────

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        $title = $record->getAttribute('title') ?? $record->getAttribute('name') ?? $record->getKey();

        return is_scalar($title) ? (string) $title : '';
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Entry $record */
        return [
            'Status' => $record->status->value,
            'Updated' => $record->updated_at?->diffForHumans() ?? '',
        ];
    }

    // ── Pages ────────────────────────────────────────────────────────────────

    public static function getPages(): array
    {
        return [
            'index' => ListEntries::route('/'),
            'create' => CreateEntry::route('/create'),
            'edit' => EditEntry::route('/{record}/edit'),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public static function getTypeHandleFromRequest(): string
    {
        $type = request()->query('type', '');

        return is_string($type) ? $type : '';
    }

    private static function resolveCurrentType(): ?ContentType
    {
        $handle = static::getTypeHandleFromRequest();
        if ($handle === '') {
            return null;
        }

        /** @var SchemaRegistry $registry */
        $registry = app(SchemaRegistry::class);

        return $registry->get($handle);
    }
}
