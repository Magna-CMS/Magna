<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Admin\Resources\RoleResource;
use Magna\Auth\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Saving a role must not throw away the grants the form cannot draw.
 *
 * The permissions field is a checkbox list built from the permission registry,
 * and registered keys never contain wildcards — only grants do. So a role
 * holding `erp.*` had no box to tick for it, submitted without it, and the
 * save path deleted every grant and rebuilt from the form. Opening the `admin`
 * role in the panel and pressing save stripped all seven of its wildcards.
 *
 * That is silent, permanent, and produces exactly the symptom that is hardest
 * to diagnose afterwards: an administrator whose screens still open, because
 * the portal reads a flag, while every API call behind them is refused.
 *
 * Reached through the resource's own helper rather than by driving Filament,
 * because the defect is in what the save does with the row, not in the form.
 */
function wildcardRole(string $handle, array $grants): Role
{
    $role = Role::query()->create(['handle' => $handle, 'name' => ucfirst($handle)]);
    $role->grant(...$grants);

    return $role->fresh();
}

/** @return list<string> */
function rewriteGrantsOn(Role $role, array $ticked): array
{
    $rewrite = new ReflectionMethod(RoleResource::class, 'rewriteGrants');
    $rewrite->invoke(null, $role, $ticked);

    return $role->fresh()->grants();
}

it('keeps a wildcard the checkbox list could never have shown', function (): void {
    $role = wildcardRole('admin', ['panel.access', 'users.*', 'erp.*']);

    // What the form submits: the concrete keys only. The wildcards are not
    // options, so they are not in the payload.
    $after = rewriteGrantsOn($role, ['panel.access']);

    expect($after)->toContain('users.*')
        ->and($after)->toContain('erp.*')
        ->and($after)->toContain('panel.access');
});

it('still removes a concrete key the editor unticked', function (): void {
    // The point is to keep what the form cannot represent, not to make every
    // grant permanent — unticking a real key must still revoke it.
    $role = wildcardRole('editor', ['content.view', 'content.publish', 'media.*']);

    $after = rewriteGrantsOn($role, ['content.view']);

    expect($after)->toContain('content.view')
        ->and($after)->toContain('media.*')
        ->and($after)->not->toContain('content.publish');
});

it('does not double up a key that was both held and ticked', function (): void {
    $role = wildcardRole('viewer', ['content.*.view', 'panel.access']);

    $after = rewriteGrantsOn($role, ['panel.access']);

    expect($after)->toHaveCount(2)
        ->and(array_count_values($after)['panel.access'])->toBe(1);
});
