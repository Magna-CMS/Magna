<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Magna\Auth\Role;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('query-budget');

// Locks the management list endpoints to a constant query count regardless of
// row count — a paginated list must never issue per-row queries (N+1).
// preventLazyLoading() (TestCase::setUp) already throws on lazy N+1; this adds
// an explicit upper bound that also catches eager per-row queries.

function budgetUsersToken(): string
{
    $role = Role::factory()->create();
    $role->grant('users.view');
    $user = User::factory()->create();
    $user->assignRole($role);
    $result = $user->createToken('mgmt', ['management'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    return $result->plainTextToken;
}

it('users list stays constant-query as rows grow (no N+1)', function (): void {
    // 15 users, each with their own role — a per-row roles query would be an N+1.
    foreach (range(1, 15) as $i) {
        $u = User::factory()->create();
        $r = Role::factory()->create();
        $u->assignRole($r);
    }

    $token = budgetUsersToken();

    $queries = [];
    DB::listen(function ($q) use (&$queries): void {
        $queries[] = $q->sql;
    });

    $this->withToken($token)->getJson('/api/v1/manage/users')->assertOk();

    // 3 auth + 1 api settings + 1 pagination count + 1 users page + 1 roles
    // eager-load = 7; small buffer. Crucially this is independent of the 16 rows.
    $count = count($queries);
    expect($count)->toBeLessThanOrEqual(9, "Users list ran {$count} queries (expected constant ≤ 9):\n".implode("\n", $queries));
});
