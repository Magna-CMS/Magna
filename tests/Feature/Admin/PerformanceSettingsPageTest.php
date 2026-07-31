<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\Admin\Pages\PerformanceSettingsPage;
use Magna\Auth\Role;
use Magna\Settings\PerformanceSettings;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

function performanceSuperAdmin(): User
{
    $role = Role::factory()->create([
        'handle' => 'super_admin',
        'name' => 'Super Admin',
        'is_super_admin' => true,
    ]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

/**
 * The Redis inputs are ->visible() behind "is a Redis driver selected", and
 * Filament drops hidden components from the form state entirely. Every path
 * that read $data['redis_host'] unconditionally therefore raised
 * "Undefined array key redis_host" — which in production is an ErrorException,
 * i.e. the whole page returning "Error while loading page".
 */
it('saves performance settings while the redis fields are hidden', function (): void {
    $this->actingAs(performanceSuperAdmin());

    Livewire::test(PerformanceSettingsPage::class)
        ->fillForm([
            'cache_driver' => 'file',
            'queue_connection' => 'database',
            'octane_server' => 'swoole',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertOk();

    $settings = PerformanceSettings::get();

    expect($settings->cache_driver)->toBe('file')
        ->and($settings->queue_connection)->toBe('database')
        ->and($settings->octane_server)->toBe('swoole');
});

it('leaves a configured redis host alone when saving on a non-redis driver', function (): void {
    $settings = PerformanceSettings::get();
    $settings->cache_driver = 'redis';
    $settings->queue_connection = 'redis';
    $settings->redis_host = 'cache.internal';
    $settings->redis_port = 6380;
    $settings->redis_database = 3;
    $settings->save();

    $this->actingAs(performanceSuperAdmin());

    // Switching off Redis hides the host input. Defaulting it here would
    // silently overwrite cache.internal with 127.0.0.1 — a value the operator
    // never typed and cannot see was lost until Redis is switched back on.
    Livewire::test(PerformanceSettingsPage::class)
        ->fillForm([
            'cache_driver' => 'file',
            'queue_connection' => 'database',
            'octane_server' => 'frankenphp',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $saved = PerformanceSettings::get();

    expect($saved->redis_host)->toBe('cache.internal')
        ->and($saved->redis_port)->toBe(6380)
        ->and($saved->redis_database)->toBe(3);
});

it('tests the redis connection without the form fields present', function (): void {
    $this->actingAs(performanceSuperAdmin());

    // The header action stays clickable on every driver. It must fall back to
    // the stored settings and report a connection failure through a
    // notification, never blow up on a missing array key.
    Livewire::test(PerformanceSettingsPage::class)
        ->fillForm([
            'cache_driver' => 'file',
            'queue_connection' => 'database',
            'octane_server' => 'frankenphp',
        ])
        ->call('testRedisConnection')
        ->assertOk();
});

it('still writes the redis fields when they are visible', function (): void {
    $this->actingAs(performanceSuperAdmin());

    Livewire::test(PerformanceSettingsPage::class)
        ->fillForm([
            'cache_driver' => 'redis',
            'queue_connection' => 'redis',
            'redis_host' => '10.0.0.5',
            'redis_port' => 6390,
            'redis_database' => 2,
            'octane_server' => 'frankenphp',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $saved = PerformanceSettings::get();

    expect($saved->redis_host)->toBe('10.0.0.5')
        ->and($saved->redis_port)->toBe(6390)
        ->and($saved->redis_database)->toBe(2);
});
