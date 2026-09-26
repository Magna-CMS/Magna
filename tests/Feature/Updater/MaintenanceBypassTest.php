<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Queue;
use Magna\Updater\CoreUpdateJob;
use Magna\Updater\CoreUpdateProgress;
use Magna\Updater\CoreUpdateStarter;
use Magna\Updater\PendingCoreUpdate;
use Tests\TestCase;

uses(TestCase::class);

/**
 * `down` used to run bare, so the moment the site went into maintenance mode
 * the admin's own progress poll answered 503 and the bar froze until `up`.
 * On the inline path the poll IS the worker, so nothing could have moved it.
 */
it('issues the admin who starts an update a maintenance bypass for that run', function (): void {
    Queue::fake();

    app(CoreUpdateStarter::class)->start(new PendingCoreUpdate(
        version: '9.9.9',
        zipUrl: 'https://github.com/magna-cms/magna/archive/v9.9.9.zip',
        expectedSha256: str_repeat('a', 64),
    ));

    $secret = CoreUpdateProgress::pending()?->maintenanceSecret;

    expect($secret)->toMatch('/^[a-f0-9]{40}$/');

    $cookie = collect(Cookie::getQueuedCookies())->first(fn ($c): bool => $c->getName() === 'laravel_maintenance');

    expect($cookie)->not->toBeNull()
        ->and(MaintenanceModeBypassCookie::isValid((string) $cookie?->getValue(), (string) $secret))->toBeTrue();

    // The secret travels with the job, so `down` is issued with the same key.
    Queue::assertPushed(CoreUpdateJob::class, fn (CoreUpdateJob $job): bool => $job->maintenanceSecret === $secret);
});

it('lets a request carrying the bypass cookie through while the site is down', function (): void {
    $secret = bin2hex(random_bytes(20));

    Artisan::call('down', ['--secret' => $secret, '--retry' => 15]);

    try {
        expect(app()->maintenanceMode()->active())->toBeTrue();

        // Laravel exempts its own /up health route from maintenance mode, so
        // the front door is the request to prove it on.
        $this->get('/')->assertStatus(503);

        $bypassed = $this
            ->withUnencryptedCookie('laravel_maintenance', (string) MaintenanceModeBypassCookie::create($secret)->getValue())
            ->get('/');

        expect($bypassed->status())->not->toBe(503);
    } finally {
        Artisan::call('up');
    }

    expect(app()->maintenanceMode()->active())->toBeFalse();
});
