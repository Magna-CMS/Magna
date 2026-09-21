<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Tests\TestCase + RefreshDatabase come from the folder mapping in Pest.php.

/*
 * The audit trail is the evidence record — a prune bug destroys evidence
 * silently, an export bug feeds a SIEM garbage — and neither command had a
 * single test.
 */

function auditRow(string $action, string $createdAt): void
{
    DB::table('audit_logs')->insert([
        'id' => (string) Str::ulid(),
        'action' => $action,
        'actor_id' => null,
        'actor_type' => null,
        'ip' => null,
        'subject_type' => null,
        'subject_id' => null,
        'before' => null,
        'after' => null,
        'created_at' => $createdAt,
    ]);
}

it('prunes only entries older than the retention window', function (): void {
    auditRow('old.action', now()->subDays(400)->toDateTimeString());
    auditRow('recent.action', now()->subDays(10)->toDateTimeString());

    $this->artisan('magna:audit:prune')
        ->expectsOutputToContain('Pruned 1 audit log entry')
        ->assertSuccessful();

    expect(DB::table('audit_logs')->pluck('action')->all())->toBe(['recent.action']);
});

it('honours a custom --days window', function (): void {
    auditRow('mid.action', now()->subDays(60)->toDateTimeString());
    auditRow('fresh.action', now()->subDays(5)->toDateTimeString());

    $this->artisan('magna:audit:prune', ['--days' => 30])->assertSuccessful();

    expect(DB::table('audit_logs')->pluck('action')->all())->toBe(['fresh.action']);
});

it('exports the window as parseable JSON lines and nothing outside it', function (): void {
    auditRow('before.window', '2026-01-01 12:00:00');
    auditRow('in.window', '2026-02-10 12:00:00');
    auditRow('after.window', '2026-03-20 12:00:00');

    Artisan::call('magna:audit:export', ['--from' => '2026-02-01', '--to' => '2026-02-28']);

    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

    expect($lines)->toHaveCount(1);

    $row = json_decode($lines[0], true);

    expect($row)->toBeArray()
        ->and($row['action'])->toBe('in.window')
        ->and($row['id'])->toBeString();
});
