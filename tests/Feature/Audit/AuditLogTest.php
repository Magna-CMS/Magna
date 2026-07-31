<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Magna\Audit\AuditLog;
use Magna\Auth\Role;
use Magna\Settings\GeneralSettings;
use Magna\Users\User;

beforeEach(function (): void {
    Cache::flush();
});

// ── Immutability ──────────────────────────────────────────────────────────────

it('prevents updating an audit log entry', function (): void {
    $log = AuditLog::record(action: 'test.event', actorType: 'system');

    expect(fn () => $log->save())->toThrow(LogicException::class);
});

it('prevents deleting an audit log entry', function (): void {
    $log = AuditLog::record(action: 'test.event', actorType: 'system');

    expect(fn () => $log->delete())->toThrow(LogicException::class);
});

// ── Auto-audited events ───────────────────────────────────────────────────────

it('records an audit entry on successful login', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    $user = User::factory()->create(['password' => Hash::make('secret')]);
    $user->assignRole(Role::factory()->withPanelAccess()->create());

    Livewire::test(Magna\Auth\Filament\Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'secret'])
        ->call('authenticate');

    expect(AuditLog::query()->where('action', 'auth.login.success')->count())->toBe(1);
});

it('records an audit entry on failed login', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    $user = User::factory()->create();

    Livewire::test(Magna\Auth\Filament\Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'wrong-password'])
        ->call('authenticate');

    expect(AuditLog::query()->where('action', 'auth.login.failure')->count())->toBe(1);
});

// S1-09: a fabricated Login event (e.g. dispatched by a compromised or
// malicious plugin from its boot() method — Login has a public constructor
// and no verification that it originated from a real Auth::attempt() call)
// must not be trusted as a genuine login just because the event fired.
it('discards a fabricated Login event whose user does not match the authenticated guard state', function (): void {
    $victim = User::factory()->create();

    // No one is actually authenticated on the 'web' guard in this request.
    event(new Login('web', $victim, false));

    expect(AuditLog::query()->where('action', 'auth.login.success')->count())->toBe(0);
});

it('records the audit entry when the Login event matches the real authenticated guard state', function (): void {
    $user = User::factory()->create();

    auth('web')->login($user);
    event(new Login('web', $user, false));

    $log = AuditLog::query()->where('action', 'auth.login.success')->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_id)->toBe($user->id); // @phpstan-ignore-line
});

it('records an audit entry when a role is assigned', function (): void {
    $user = User::factory()->create();
    $role = Role::factory()->withPanelAccess()->create(['handle' => 'editor']);

    $user->assignRole($role);

    $log = AuditLog::query()->where('action', 'roles.assigned')->first();
    expect($log)->not->toBeNull();
    expect($log->after)->toMatchArray(['role' => 'editor']); // @phpstan-ignore-line
});

it('records an audit entry when settings are changed', function (): void {
    $settings = GeneralSettings::get();
    $settings->site_name = 'Audited Site';
    $settings->save();

    $log = AuditLog::query()->where('action', 'settings.changed')->first();
    expect($log)->not->toBeNull();
    expect($log->after)->toMatchArray(['site_name' => 'Audited Site']); // @phpstan-ignore-line
});

it('records an audit entry when an API token is created', function (): void {
    $user = User::factory()->create();
    $expiresAt = now()->addDays(30);
    $mgmt = $user->createToken('mgmt', ['management'], $expiresAt);
    $mgmt->accessToken->forceFill(['scope' => 'management'])->save();

    $this->withToken($mgmt->plainTextToken)
        ->postJson(route('api.tokens.store'), [
            'name' => 'Delivery key',
            'scope' => 'delivery',
        ])
        ->assertCreated();

    expect(AuditLog::query()->where('action', 'tokens.created')->count())->toBe(1);
});

it('records an audit entry when an API token is revoked', function (): void {
    $user = User::factory()->create();
    $expiresAt = now()->addDays(30);
    $mgmt = $user->createToken('mgmt', ['management'], $expiresAt);
    $mgmt->accessToken->forceFill(['scope' => 'management'])->save();
    $tokenId = $mgmt->accessToken->id;

    $this->withToken($mgmt->plainTextToken)
        ->deleteJson(route('api.tokens.destroy', $tokenId))
        ->assertOk();

    expect(AuditLog::query()->where('action', 'tokens.revoked')->count())->toBe(1);
});

// Stage 13 (S5-02): audit_logs had no pruning mechanism at all — grew
// unbounded on every admin/moderation action.
it('magna:audit:prune deletes only entries older than the retention window', function (): void {
    // AuditLog::save()/delete() are hard-overridden to throw for existing
    // records (append-only enforcement) — backdate via the query builder
    // directly, matching the prune command's own DB::table() approach.
    $old = AuditLog::record(action: 'test.old', actorType: 'system');
    DB::table('audit_logs')->where('id', $old->id)->update(['created_at' => now()->subDays(400)]);

    $recent = AuditLog::record(action: 'test.recent', actorType: 'system');

    Artisan::call('magna:audit:prune', ['--days' => '365']);

    expect(AuditLog::find($old->id))->toBeNull()
        ->and(AuditLog::find($recent->id))->not->toBeNull();
});
