<?php

declare(strict_types=1);

namespace Magna\Admin\Resources\Role;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Magna\Admin\Resources\RoleResource;
use Magna\Auth\Role;
use Magna\Users\User;

class ManageRoles extends ManageRecords
{
    protected static string $resource = RoleResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        $createKeys = [];

        return [
            CreateAction::make()
                ->visible(fn (): bool => auth()->user()?->can('roles.manage') ?? false)
                ->using(function (array $data, string $model) use (&$createKeys): Role {
                    // S1-02 defense in depth: never let a non-super-admin actor
                    // create a new super-admin role, regardless of client state.
                    if (! (User::current()?->isSuperAdmin() ?? false)) {
                        $data['is_super_admin'] = false;
                    }

                    $createKeys = $data['permission_keys'] ?? [];
                    /** @var Role $record */
                    $record = new $model;
                    $record->fill($data);
                    $record->save();
                    foreach ($createKeys as $key) {
                        $record->grant($key);
                    }

                    return $record;
                }),
        ];
    }
}
