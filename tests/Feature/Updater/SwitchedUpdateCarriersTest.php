<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Magna\MagnaServiceProvider;
use Magna\Updater\CoreUpdateProgress;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\Run\FirstRequestFinalizer;
use Magna\Updater\Run\RunOutcome;
use Magna\Updater\Run\RunRollback;
use Magna\Updater\Run\RunState;
use Magna\Updater\Run\UpdateJournal;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * A switched update is finished under the new code by whichever carrier
 * gets there first. On the first live hop every carrier failed to arrive:
 * the admin's bypass cookie had been encrypted on its way out, so the poll
 * was answered 503; the scheduler skips its events while the site is down,
 * so neither the minute tick nor the queue drain ran; and the site sat in
 * maintenance mode until someone ran the CLI. Each of those has a test now,
 * and a fourth carrier that cannot fail to arrive: the request itself.
 */
function carrierInstall(): UpdatePaths
{
    $base = sys_get_temp_dir().'/magna-carrier-'.bin2hex(random_bytes(6));
    mkdir($base.'/storage/app/magna-updates', 0777, true);
    mkdir($base.'/vendor/composer', 0777, true);
    file_put_contents($base.'/vendor/composer/autoload_classmap.php', '<?php return [];');

    $paths = new UpdatePaths($base, $base.'/storage');
    app()->instance(UpdatePaths::class, $paths);

    UpdateJournal::create($paths, 'run1', [
        'mode' => 'update',
        'from' => '1.0.0',
        'to' => MagnaServiceProvider::VERSION,
        'check_files' => [],
        'check_classes' => [],
        'removed_classes' => [],
    ])->transition(RunState::FinalizePending);

    return $paths;
}

function scheduledEvent(string $command): ?Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, $command)) {
            return $event;
        }
    }

    return null;
}

it('runs the resume tick while the site is in maintenance mode', function (): void {
    $event = scheduledEvent('magna:core:resume');

    expect($event)->not->toBeNull()
        ->and($event?->runsInMaintenanceMode())->toBeTrue();
});

it('drains the queue while the site is in maintenance mode', function (): void {
    config(['queue.default' => 'database']);

    $event = scheduledEvent('queue:work');

    expect($event)->not->toBeNull()
        ->and($event?->runsInMaintenanceMode())->toBeTrue();
});

it('hands the bypass cookie to the browser unencrypted, so the maintenance middleware can read it back', function (): void {
    $secret = bin2hex(random_bytes(20));

    Route::middleware('web')->get('/_magna-test/bypass', function () use ($secret) {
        Cookie::queue(MaintenanceModeBypassCookie::create($secret));

        return response('issued');
    });

    $response = $this->get('/_magna-test/bypass');
    $response->assertOk();

    $raw = $response->getCookie('laravel_maintenance', decrypt: false);

    expect($raw)->not->toBeNull()
        ->and(MaintenanceModeBypassCookie::isValid((string) $raw?->getValue(), $secret))->toBeTrue();

    // And the proof that matters: the browser sends that value back, and gets in.
    Artisan::call('down', ['--secret' => $secret, '--retry' => 15]);

    try {
        expect($this->get('/')->status())->toBe(503);

        $bypassed = $this->withUnencryptedCookie('laravel_maintenance', (string) $raw?->getValue())->get('/');

        expect($bypassed->status())->not->toBe(503);
    } finally {
        Artisan::call('up');
    }
});

it('finishes a switched update from the first request that boots the new release', function (): void {
    $paths = carrierInstall();
    file_put_contents(RunRollback::bootGuardMarker($paths), (string) json_encode(['run' => 'run1']));
    CoreUpdateProgress::set(CoreUpdateState::Switched, 'Switched…', 80, MagnaServiceProvider::VERSION);
    Artisan::call('down');

    try {
        $outcome = app(FirstRequestFinalizer::class)->run();

        expect($outcome?->kind)->toBe(RunOutcome::COMPLETED)
            ->and(UpdateJournal::open($paths, 'run1')?->state())->toBe(RunState::Completed)
            ->and(app()->maintenanceMode()->active())->toBeFalse()
            ->and(is_file(RunRollback::bootGuardMarker($paths)))->toBeFalse();
    } finally {
        Artisan::call('up');
        CoreUpdateProgress::forget();
        (new Filesystem)->remove($paths->basePath);
    }
});

it('costs an idle request nothing more than one stat', function (): void {
    $paths = carrierInstall();

    try {
        // No marker: nothing is switched, nothing is touched — the journal
        // planted above stays exactly as it was.
        expect(app(FirstRequestFinalizer::class)->run())->toBeNull()
            ->and(UpdateJournal::open($paths, 'run1')?->state())->toBe(RunState::FinalizePending);
    } finally {
        (new Filesystem)->remove($paths->basePath);
    }
});
