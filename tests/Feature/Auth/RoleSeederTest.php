<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Magna\Auth\PermissionRegistry;
use Magna\Auth\Role;
use Magna\Users\User;

it('creates the four default roles', function (): void {
    $this->seed(RoleSeeder::class);

    expect(Role::query()->pluck('handle')->all())
        ->toEqualCanonicalizing(['super-admin', 'admin', 'editor', 'viewer'])
        ->and(Role::query()->where('handle', 'super-admin')->firstOrFail()->is_super_admin)->toBeTrue()
        ->and(Role::query()->where('handle', 'admin')->firstOrFail()->grants())
        ->toContain('users.*', 'roles.*', 'settings.*', 'plugins.*', 'audit.*');
});

it('is idempotent', function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    expect(Role::query()->count())->toBe(4)
        // panel.access, users.*, roles.*, settings.*, plugins.*, audit.*,
        // blocks.preview, blocks.raw_html
        ->and(Role::query()->where('handle', 'admin')->firstOrFail()->permissions()->count())->toBe(8);
});

it('gives seeded roles working permissions through the gate', function (): void {
    $this->seed(RoleSeeder::class);
    app(PermissionRegistry::class)->registerMany([
        'content.article.view',
        'content.article.publish',
    ]);

    $viewer = User::factory()->create();
    $viewer->assignRole('viewer');

    $editor = User::factory()->create();
    $editor->assignRole('editor');

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect($viewer->can('content.article.view'))->toBeTrue()
        ->and($viewer->can('content.article.publish'))->toBeFalse()
        ->and($editor->can('content.article.publish'))->toBeTrue()
        ->and($admin->can('users.manage'))->toBeTrue()
        ->and($admin->can('content.article.publish'))->toBeFalse();
});
