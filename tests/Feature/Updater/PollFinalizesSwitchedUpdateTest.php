<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Magna\Admin\Pages\SystemInfoPage;
use Magna\Auth\Role;
use Magna\MagnaServiceProvider;
use Magna\Updater\CoreUpdateProgress;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\Run\RunState;
use Magna\Updater\Run\UpdateJournal;
use Magna\Updater\UpdatePaths;
use Magna\Users\User;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    CoreUpdateProgress::forget();
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

/**
 * On a host with neither a worker nor cron, the admin's own poll is the one
 * process on the new code that will finish a switched update.
 */
function pollInstall(): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-poll-'.bin2hex(random_bytes(6));
    mkdir($base.'/storage/app', 0777, true);
    mkdir($base.'/vendor/composer', 0777, true);
    file_put_contents($base.'/vendor/composer/autoload_classmap.php', '<?php return [];');

    $paths = new UpdatePaths($base, $base.'/storage');
    app()->instance(UpdatePaths::class, $paths);

    // A run whose switch is done and whose target is the code this process runs.
    UpdateJournal::create($paths, 'run1', [
        'mode' => 'update',
        'from' => '1.0.0',
        'to' => MagnaServiceProvider::VERSION,
        'check_files' => [],
        'check_classes' => ['Magna\\Updater\\CoreUpdater'],
        'removed_classes' => [],
    ])->transition(RunState::FinalizePending);

    CoreUpdateProgress::set(CoreUpdateState::Switched, 'Switched…', 80, MagnaServiceProvider::VERSION);
    Artisan::call('down');

    return $paths;
}

function pollSuperAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('finishes a switched update from the admin poll and brings the site up', function (): void {
    $paths = pollInstall();
    $this->actingAs(pollSuperAdmin());

    try {
        Livewire::test(SystemInfoPage::class)
            ->set('updating', true)
            ->call('pollCoreUpdate')
            ->assertSet('updating', false);

        expect(UpdateJournal::latestPending($paths))->toBeNull()
            ->and(UpdateJournal::open($paths, 'run1')?->state())->toBe(RunState::Completed)
            ->and(app()->maintenanceMode()->active())->toBeFalse()
            ->and((new InstalledFootprint($paths))->read()['version'] ?? null)->toBe(MagnaServiceProvider::VERSION)
            ->and(CoreUpdateProgress::read()['state'])->toBe('completed');
    } finally {
        Artisan::call('up');
        (new Filesystem)->remove($paths->basePath);
    }
});

it('leaves a switched update alone for a user who cannot manage settings', function (): void {
    $paths = pollInstall();

    $role = Role::factory()->create(['handle' => 'viewer', 'name' => 'Viewer']);
    $role->grant('settings.view');
    $viewer = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $viewer->assignRole($role);
    $this->actingAs($viewer);

    try {
        Livewire::test(SystemInfoPage::class)
            ->set('updating', true)
            ->call('pollCoreUpdate')
            ->assertSet('updating', true);

        expect(UpdateJournal::latestPending($paths)?->state())->toBe(RunState::FinalizePending)
            ->and(app()->maintenanceMode()->active())->toBeTrue();
    } finally {
        Artisan::call('up');
        (new Filesystem)->remove($paths->basePath);
    }
});
