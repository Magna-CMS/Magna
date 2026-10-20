<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Magna\Auth\Role;
use Magna\Updater\Events\CoreUpdateFailed;
use Magna\Users\User;
use Tests\TestCase;

/*
 * CoreUpdateFailed was dispatched by the rollback and the finalizer and heard
 * by nobody — an update that rolled itself back left no trace an admin would
 * see. It now lands in the bell, worded for which release is live.
 */

uses(TestCase::class, RefreshDatabase::class);

/** @return array<string, mixed> */
function failedUpdateBell(CoreUpdateFailed $event): array
{
    $role = Role::factory()->create(['is_super_admin' => true]);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    event($event);

    return (array) json_decode((string) DB::table('notifications')->where('notifiable_id', $admin->id)->value('data'), true);
}

it('says a rolled-back update put the previous release back', function (): void {
    $bell = failedUpdateBell(new CoreUpdateFailed('1.6.0', 'v1.6.1', 'Boot check failed.', rolledBack: true));

    expect($bell['title'] ?? null)->toBe('Update to v1.6.1 was rolled back')
        ->and($bell['body'] ?? '')->toContain('v1.6.0')
        ->and($bell['status'] ?? null)->toBe('warning');
});

it('says an unfinished update needs attention', function (): void {
    $bell = failedUpdateBell(new CoreUpdateFailed('1.6.0', '1.6.1', 'A migration failed.', rolledBack: false));

    expect($bell['title'] ?? null)->toBe('Update to v1.6.1 needs attention')
        ->and($bell['status'] ?? null)->toBe('danger');
});
