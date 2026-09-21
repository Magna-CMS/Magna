<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Audit\AuditLog;
use Magna\Auth\Role;
use Magna\Install\EnvWriter;
use Magna\Support\DebugWindow;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Debug mode may be opened from the panel, but only for a while.
 *
 * The danger in APP_DEBUG is not switching it on to chase a fault — it is
 * forgetting to switch it back off, which leaves a production site showing
 * stack traces, SQL and environment values to every visitor indefinitely. So
 * the panel opens a window with an expiry and the next request past it closes
 * the flag again, with no cron and no queue worker involved.
 */
function debugWindowIn(string $dir): DebugWindow
{
    @mkdir($dir, 0777, true);

    return new DebugWindow(new EnvWriter($dir.'/.env'), $dir.'/stamp.json');
}

function debugTempDir(): string
{
    return sys_get_temp_dir().'/magna-debug-'.bin2hex(random_bytes(6));
}

it('closes a window the moment it has run out', function (): void {
    $dir = debugTempDir();
    $window = debugWindowIn($dir);

    $window->open(30);
    expect($window->isOpen())->toBeTrue()
        ->and(file_get_contents($dir.'/.env'))->toContain('APP_DEBUG=true');

    // The window runs out while nobody is looking; the next request enforces.
    $this->travel(31)->minutes();
    $window->enforce();

    expect($window->isOpen())->toBeFalse()
        ->and(file_get_contents($dir.'/.env'))->toContain('APP_DEBUG=false')
        ->and(is_file($dir.'/stamp.json'))->toBeFalse();
});

it('leaves an open window alone until it expires', function (): void {
    $dir = debugTempDir();
    $window = debugWindowIn($dir);
    $window->open(30);

    $this->travel(29)->minutes();
    $window->enforce();

    expect(file_get_contents($dir.'/.env'))->toContain('APP_DEBUG=true');
});

it('never touches a flag the operator set at the shell', function (): void {
    // No stamp means nobody opened a window from the panel, so the flag is
    // somebody's deliberate server-side decision and stays exactly as it is.
    $dir = debugTempDir();
    @mkdir($dir, 0777, true);
    file_put_contents($dir.'/.env', "APP_DEBUG=true\n");

    debugWindowIn($dir)->enforce();

    expect(file_get_contents($dir.'/.env'))->toContain('APP_DEBUG=true');
});

it('refuses to be asked for a window longer than the ceiling', function (): void {
    $dir = debugTempDir();
    $window = debugWindowIn($dir);

    $expires = $window->open(60 * 24);

    expect($expires->diffInMinutes(now()))->toBeLessThanOrEqual(DebugWindow::MAX_MINUTES);
});

it('treats an unreadable stamp as no window and closes the flag', function (): void {
    $dir = debugTempDir();
    $window = debugWindowIn($dir);
    $window->open(30);
    file_put_contents($dir.'/stamp.json', 'not json at all');

    $window->enforce();

    expect(file_get_contents($dir.'/.env'))->toContain('APP_DEBUG=false');
});

// ─── The routed control ─────────────────────────────────────────────────────

function debugModeSuperAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('refuses the debug control to an admin who is not a super admin', function (): void {
    // settings.manage is not enough: this control can expose the whole site,
    // so it is held to accounts that could grant themselves anything anyway.
    $role = Role::factory()->create(['handle' => 'ops', 'name' => 'Ops']);
    $role->grant('settings.view', 'settings.manage');
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    $this->actingAs($user)->post(route('magna.admin.debug-mode'))->assertForbidden();
});

it('refuses the debug control to a settings.view-only user', function (): void {
    $role = Role::factory()->create(['handle' => 'auditor', 'name' => 'Auditor']);
    $role->grant('settings.view');
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    $this->actingAs($user)->post(route('magna.admin.debug-mode'))->assertForbidden();
});

it('refuses the debug control to a guest', function (): void {
    $this->post(route('magna.admin.debug-mode'))->assertRedirect();
});

it('records who opened the window', function (): void {
    $dir = debugTempDir();
    @mkdir($dir, 0777, true);
    config([
        'magna.install.env_path' => $dir.'/.env',
        'magna.debug_window.stamp_path' => $dir.'/stamp.json',
        'app.debug' => false,
    ]);
    app()->forgetInstance(DebugWindow::class);

    $this->actingAs(debugModeSuperAdmin())
        ->from('/system-info-page')
        ->post(route('magna.admin.debug-mode'))
        ->assertRedirect('/system-info-page');

    expect(file_get_contents($dir.'/.env'))->toContain('APP_DEBUG=true')
        ->and(AuditLog::query()->where('action', 'system.debug_mode.opened')->exists())->toBeTrue();
});
