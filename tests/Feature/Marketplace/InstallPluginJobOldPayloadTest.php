<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Magna\Auth\Role;
use Magna\Marketplace\InstallPluginJob;
use Magna\Marketplace\InstallProgress;
use Magna\Marketplace\InstallState;
use Magna\Marketplace\PluginInstaller;
use Magna\Users\User;
use Tests\TestCase;

/*
 * A queued job outlives the release that queued it. Install jobs queued
 * before the `update` flag existed were unserialized into the newer class
 * with the flag UNINITIALIZED — unserialization applies a declared property
 * default, never a constructor's — and died with "Typed property
 * InstallPluginJob::$update must not be accessed before initialization".
 * Two such jobs sat in production's failed_jobs, and the admin who clicked
 * saw nothing happen.
 */

uses(TestCase::class, RefreshDatabase::class);

it('runs a payload queued before the update flag existed, as an install', function (): void {
    /** @var InstallPluginJob $job */
    $job = (new ReflectionClass(InstallPluginJob::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(InstallPluginJob::class, 'package'))->setValue($job, 'magna-cms/docs');

    expect($job->update)->toBeFalse();

    $installer = Mockery::mock(PluginInstaller::class);
    $installer->shouldReceive('install')->once()->with('magna-cms/docs')->andReturn(InstallState::Completed);
    $installer->shouldNotReceive('update');

    $job->handle($installer);
});

it('survives a real serialize round trip with the flag intact', function (): void {
    $job = unserialize(serialize(new InstallPluginJob('magna-cms/docs', update: true)));

    expect($job->update)->toBeTrue()
        ->and($job->package)->toBe('magna-cms/docs');
});

it('reports a job that dies outside the installer instead of leaving it queued', function (): void {
    $role = Role::factory()->create(['is_super_admin' => true]);
    $admin = User::factory()->create();
    $admin->assignRole($role);
    InstallProgress::markQueued('magna-cms/docs', update: true);

    (new InstallPluginJob('magna-cms/docs', update: true))->failed(new RuntimeException('worker was killed'));

    $bell = json_decode((string) DB::table('notifications')->where('notifiable_id', $admin->id)->value('data'), true);

    expect(InstallProgress::read('magna-cms/docs')['state'])->toBe(InstallState::Failed->value)
        ->and(InstallProgress::isPending('magna-cms/docs'))->toBeFalse()
        ->and($bell['title'] ?? null)->toBe("Couldn't update magna-cms/docs");
});
