<?php

declare(strict_types=1);

namespace Magna\Admin\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Magna\Admin\Resources\Role\ManageRoles;
use Magna\Auth\PermissionRegistry;
use Magna\Auth\Role;
use Magna\Users\User;

class RoleResource extends \Filament\Resources\Resource
{
    protected static ?string $model = Role::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'Access';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('roles.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('roles.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('roles.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        if (! (auth()->user()?->can('roles.manage') ?? false)) {
            return false;
        }

        /** @var Role $record */
        return ! self::isLastSuperAdminRole($record);
    }

    private static function isLastSuperAdminRole(Role $role): bool
    {
        return $role->is_super_admin && Role::where('is_super_admin', true)->count() <= 1;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('handle')
                ->label('Handle')
                ->required()
                ->maxLength(100)
                ->alphaDash()
                ->unique(ignoreRecord: true)
                ->helperText('Lowercase slug used in code, e.g. "content_editor".'),

            TextInput::make('name')
                ->label('Display name')
                ->required()
                ->maxLength(255),

            Textarea::make('description')
                ->label('Description')
                ->rows(2)
                ->maxLength(1000),

            Toggle::make('is_super_admin')
                ->label('Super admin')
                ->helperText('Super admins bypass all permission checks. Only an existing super admin can grant or revoke this.')
                ->inline(false)
                ->disabled(fn (): bool => ! (User::current()?->isSuperAdmin() ?? false))
                ->dehydrated(fn (): bool => User::current()?->isSuperAdmin() ?? false),

            CheckboxList::make('permission_keys')
                ->label('Permissions')
                ->options(fn (): array => collect(app(PermissionRegistry::class)->all())
                    ->map(fn (?string $desc, string $key): string => $desc ? "{$key} — {$desc}" : $key)
                    ->all())
                ->columns(2)
                ->searchable()
                ->helperText('Select the permission keys this role grants. Ignored for super admin roles.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $editKeys = [];

        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('handle')
                    ->sortable()
                    ->searchable()
                    ->fontFamily('mono'),

                BadgeColumn::make('is_super_admin')
                    ->label('Super admin')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->colors([
                        'danger' => true,
                        'gray' => false,
                    ]),

                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->sortable(),

                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions')
                    ->sortable(),
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn (Role $record): bool => (auth()->user()?->can('roles.manage') ?? false)
                        && ! self::isLastSuperAdminRole($record)
                    )
                    ->mutateRecordDataUsing(fn (array $data, Role $record): array => array_merge($data, [
                        'permission_keys' => $record->grants(),
                    ]))
                    ->using(function (Role $record, array $data) use (&$editKeys): void {
                        // S1-02 defense in depth: the form field is disabled+non-dehydrated
                        // for non-super-admins, but never trust client state for this flag.
                        if (! (User::current()?->isSuperAdmin() ?? false)) {
                            $data['is_super_admin'] = $record->is_super_admin;
                        }

                        $editKeys = $data['permission_keys'] ?? [];
                        $record->update($data);
                        $record->permissions()->delete();
                        foreach ($editKeys as $key) {
                            $record->grant($key);
                        }
                    }),
                DeleteAction::make()
                    ->visible(fn (Role $record): bool => (auth()->user()?->can('roles.manage') ?? false)
                        && ! self::isLastSuperAdminRole($record)
                    ),
            ])
            ->defaultSort('name');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ManageRoles::route('/'),
        ];
    }
}
