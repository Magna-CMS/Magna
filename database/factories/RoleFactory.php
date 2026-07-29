<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Magna\Auth\Role;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * A role that may open the admin panel.
     *
     * Deliberately a state rather than the default: the factory's job is to
     * make a role, and a role grants nothing until somebody says it does.
     * Baking `panel.access` into every factory role would make the tests that
     * check the panel door pass whether or not the door works.
     */
    public function withPanelAccess(): static
    {
        return $this->afterCreating(function (Role $role): void {
            $role->grant('panel.access');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'handle' => str($name)->slug()->toString(),
            'name' => $name,
            'description' => null,
            'is_super_admin' => false,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_super_admin' => true,
        ]);
    }
}
