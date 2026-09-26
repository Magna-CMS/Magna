<?php

declare(strict_types=1);

use Magna\MagnaServiceProvider;
use Magna\Updater\CoreUpdateJob;
use Magna\Updater\CoreUpdater;
use Tests\TestCase;

uses(TestCase::class);

/*
 * A queued job outlives the release that queued it. The job that performs an
 * update is queued by the OLD code and, when its first attempt is cut short,
 * retried by a worker already running the NEW one — which unserializes the
 * old payload into the new class, leaving every property the payload never
 * carried UNINITIALIZED (unserialization does not apply constructor
 * defaults). The first live 1.4.1 hop retried exactly such a job and died on
 * "$mode must not be accessed before initialization" three times instead of
 * noticing the update was already done.
 */
it('handles a payload queued by a release that predates mode and the maintenance secret', function (): void {
    /** @var CoreUpdateJob $job */
    $job = (new ReflectionClass(CoreUpdateJob::class))->newInstanceWithoutConstructor();

    // What a 1.4.1-era payload carried, and nothing else: the target, the
    // archive, its checksum, the force flag and the signature.
    foreach ([
        'targetVersion' => MagnaServiceProvider::VERSION,
        'zipUrl' => 'https://github.com/magna-cms/magna/archive/old.zip',
        'expectedSha256' => str_repeat('a', 64),
        'force' => false,
        'checksumSignature' => null,
    ] as $property => $value) {
        $ref = new ReflectionProperty(CoreUpdateJob::class, $property);
        $ref->setValue($job, $value);
    }

    // The target equals the running version, so the only correct behaviour
    // is the quiet "someone already applied this" return — reached only if
    // the uninitialized properties are never touched directly.
    $job->handle(app(CoreUpdater::class));

    expect(true)->toBeTrue();
});
