<?php

declare(strict_types=1);

namespace Magna\Admin\Resources\User;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Magna\Admin\Resources\UserResource;
use Magna\Auth\Role;
use Magna\Users\User;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Defense in depth against S1-02: a non-super-admin actor must never be
     * able to grant or revoke super-admin role membership by tampering with
     * the submitted 'roles' array, regardless of what the (already-filtered)
     * form UI offers. Super-admin role membership is left exactly as-is for
     * non-super-admin actors.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (User::current()?->isSuperAdmin() ?? false) {
            return $data;
        }

        $superAdminRoleIds = $this->idStrings(Role::query()->where('is_super_admin', true)->pluck('id')->all());

        /** @var User $record */
        $record = $this->record;
        $currentSuperAdminIds = array_values(array_intersect(
            $this->idStrings($record->roles()->pluck('roles.id')->all()),
            $superAdminRoleIds,
        ));

        $submittedRoles = is_array($data['roles'] ?? null) ? $this->idStrings($data['roles']) : [];

        $data['roles'] = array_values(array_unique(array_merge(
            array_diff($submittedRoles, $superAdminRoleIds),
            $currentSuperAdminIds,
        )));

        return $data;
    }

    /**
     * Role ids as strings, whatever mix of ints and strings the source held
     * — the set arithmetic above compares by string value either way.
     *
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    private function idStrings(array $ids): array
    {
        $strings = [];

        foreach ($ids as $id) {
            if (is_int($id) || is_string($id)) {
                $strings[] = (string) $id;
            }
        }

        return $strings;
    }
}
